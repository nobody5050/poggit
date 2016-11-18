<?php

/*
 * Poggit
 *
 * Copyright (C) 2016 Poggit
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace poggit\builder;

use Phar;
use poggit\builder\cause\V2BuildCause;
use poggit\builder\lint\BuildResult;
use poggit\builder\lint\CloseTagLint;
use poggit\builder\lint\DirectStdoutLint;
use poggit\builder\lint\InternalBuildError;
use poggit\builder\lint\NonPsrLint;
use poggit\builder\lint\PharTooLargeBuildError;
use poggit\module\webhooks\repo\RepoWebhookHandler;
use poggit\module\webhooks\repo\StopWebhookExecutionException;
use poggit\module\webhooks\repo\WebhookProjectModel;
use poggit\Poggit;
use poggit\resource\ResourceManager;
use stdClass;

abstract class ProjectBuilder {
    static $PLUGIN_BUILDERS = [
        "default" => DefaultProjectBuilder::class,
        "nowhere" => NowHereProjectBuilder::class,
    ];
    static $LIBRARY_BUILDERS = [
        "virion" => PoggitVirionBuilder::class,
    ];

    /**
     * @param RepoZipball           $zipball
     * @param stdClass              $repoData
     * @param WebhookProjectModel[] $projects
     * @param string[]              $commitMessages
     * @param string[]              $changedFiles
     * @param V2BuildCause          $cause
     * @param int                   $triggerUserId
     * @param callable              $buildNumber
     * @param int                   $buildClass
     * @param string                $branch
     * @param string                $sha
     *
     * @throws StopWebhookExecutionException
     */
    public static function buildProjects(RepoZipball $zipball, stdClass $repoData, array $projects, array $commitMessages, array $changedFiles,
                                         V2BuildCause $cause, int $triggerUserId, callable $buildNumber, int $buildClass, string $branch, string $sha) {
        $cnt = (int) Poggit::queryAndFetch("SELECT COUNT(*) AS cnt FROM builds WHERE triggerUser = ? AND 
            UNIX_TIMESTAMP() - UNIX_TIMESTAMP(created) < 604800", "i", $triggerUserId)[0]["cnt"];

        /** @var WebhookProjectModel[] $needBuild */
        $needBuild = [];

        // parse commit message
        $needBuildNames = [];
        foreach($commitMessages as $message) {
            if(preg_match_all('/poggit[:,] (please )?build ([a-z0-9\-_., ]+)/i', $message, $matches, PREG_SET_ORDER)) {
                foreach($matches[2] as $match) {
                    foreach(array_filter(explode(",", $match)) as $name) {
                        if($name === "none" or $name === "shutup" or $name === "shut up" or $name === "none of your business" or $name === "noyb") {
                            $needBuildNames = [];
                            $wild = true;
                            break 3;
                        } elseif($name === "all") {
                            $needBuild = $projects;
                            $wild = true;
                            break 3;
                        } else {
                            $needBuildNames[] = strtolower(trim($name));
                        }
                    }
                }
            }
        }

        // scan needBuild projects
        if(!isset($wild)) {
            foreach($projects as $project) {
                if($project->devBuilds === 0) {
                    $needBuild[] = $project;
                    continue;
                }
                foreach($needBuildNames as $name) {
                    $name = strtolower(trim($name));
                    if($name === strtolower($project->name)) {
                        $needBuild[] = $project;
                        continue 2;
                    } elseif($name === "none") {
                        continue 2;
                    }
                }
                foreach($changedFiles as $fileName) {
                    if($fileName === ".poggit/.poggit.yml" or $fileName === ".poggit.yml" or Poggit::startsWith($fileName, $project->path)) {
                        $needBuild[] = $project;
                        continue 2;
                    }
                }
            }
        }
        // declare pending
        foreach($needBuild as $project) {
            Poggit::ghApiPost("repos/" . ($repoData->owner->login ?? $repoData->owner->name) . // blame GitHub
                "/{$repoData->name}/statuses/$sha", [
                "state" => "pending",
                "description" => "Build in progress",
                "context" => $context = "poggit-ci/" . preg_replace('$ _/\.$', "-", $project->name)
            ], RepoWebhookHandler::$token);
        }
        foreach($needBuild as $project) {
            if($cnt >= Poggit::MAX_WEEKLY_BUILDS) {
                throw new StopWebhookExecutionException("Resend this delivery later. This user has created $cnt Poggit-CI builds in the past week.");
            }
            $cnt++;
            $modelName = $project->framework;
            $builderList = $project->type === Poggit::PROJECT_TYPE_LIBRARY ? self::$LIBRARY_BUILDERS : self::$PLUGIN_BUILDERS;
            $builderClass = $builderList[strtolower($modelName)];
            /** @var ProjectBuilder $builder */
            $builder = new $builderClass();
            $builder->init($zipball, $repoData, $project, $cause, $triggerUserId, $buildNumber, $buildClass, $branch, $sha);
        }
    }

    private function init(RepoZipball $zipball, stdClass $repoData, WebhookProjectModel $project, V2BuildCause $cause, int $triggerUserId, callable $buildNumberGetter,
                          int $buildClass, string $branch, string $sha) {
        $buildId = (int) Poggit::queryAndFetch("SELECT IFNULL(MAX(buildId), 19200) + 1 AS nextBuildId FROM builds")[0]["nextBuildId"];
        Poggit::queryAndFetch("INSERT INTO builds (buildId, projectId) VALUES (?, ?)", "ii", $buildId, $project->projectId);
        $buildNumber = $buildNumberGetter($project);

        $accessFilters = [];
        if($repoData->private) {
            $accessFilters[] = [
                "type" => "repoAccess",
                "repo" => [
                    "id" => $repoData->id,
                    "owner" => $repoData->owner->name,
                    "name" => $repoData->name,
                    "requiredPerms" => ["pull"]
                ]
            ];
        }
        $rsrFile = ResourceManager::getInstance()->createResource("phar", "application/octet-stream", $accessFilters, $rsrId);

        $phar = new Phar($rsrFile);
        $phar->startBuffering();
        $phar->setSignatureAlgorithm(Phar::SHA1);
        $metadata = [
            "builder" => "PoggitCI/" . Poggit::POGGIT_VERSION . " " . $this->getName() . "/" . $this->getVersion(),
            "buildTime" => date(DATE_ISO8601),
            "poggitBuildId" => $buildId,
            "projectBuildNumber" => $buildNumber,
            "class" => $buildClassName = Poggit::$BUILD_CLASS_HUMAN[$buildClass]
        ];
        $phar->setMetadata($metadata);

        try {
            $buildResult = $this->build($phar, $zipball, $project);
        } catch(\Throwable $e) {
            $buildResult = new BuildResult();
            $buildResult->worstLevel = BuildResult::LEVEL_BUILD_ERROR;
            $status = new InternalBuildError();
            $status->exception = [
                "class" => get_class($e),
                "message" => $e->getMessage(),
                "file" => $e->getFile(),
                "line" => $e->getLine(),
                "code" => $e->getCode(),
            ];
            echo "Encountered error: " . json_encode($status);
            $buildResult->statuses = [$status];
        }

        $phar->stopBuffering();
        $maxSize = Poggit::MAX_PHAR_SIZE;
        if(($size = filesize($rsrFile)) > $maxSize) {
            $status = new PharTooLargeBuildError();
            $status->size = $size;
            $status->maxSize = $maxSize;
            $buildResult->addStatus($status);
        }

        if($buildResult->worstLevel === BuildResult::LEVEL_BUILD_ERROR) {
            $rsrId = ResourceManager::NULL_RESOURCE;
            @unlink($rsrFile);
        }
        Poggit::queryAndFetch("UPDATE builds SET resourceId = ?, class = ?, branch = ?, cause = ?, internal = ?, status = ?, triggerUser = ? WHERE buildId = ?",
            "iissisii", $rsrId, $buildClass, $branch, json_encode($cause, JSON_UNESCAPED_SLASHES), $buildNumber,
            json_encode($buildResult->statuses, JSON_UNESCAPED_SLASHES), $triggerUserId, $buildId);
        $lintStats = [];
        foreach($buildResult->statuses as $status) {
            switch($status->level) {
                case BuildResult::LEVEL_BUILD_ERROR:
                    $lintStats["build error"] = ($lintStats["build error"] ?? 0) + 1;
                    break;
                case BuildResult::LEVEL_ERROR:
                    $lintStats["error"] = ($lintStats["error"] ?? 0) + 1;
                    break;
                case BuildResult::LEVEL_WARN:
                    $lintStats["warning"] = ($lintStats["warning"] ?? 0) + 1;
                    break;
                case BuildResult::LEVEL_LINT:
                    $lintStats["lint"] = ($lintStats["lint"] ?? 0) + 1;
                    break;
            }
        }
        $messages = [];
        foreach($lintStats as $type => $count) {
            $messages[] = $count . " " . $type . ($count > 1 ? "s" : "") . ", ";
        }
        Poggit::ghApiPost("repos/" . ($repoData->owner->login ?? $repoData->owner->name) . // blame GitHub
            "/{$repoData->name}/statuses/$sha", $statusData = [
            "state" => BuildResult::$states[$buildResult->worstLevel],
            "target_url" => Poggit::getSecret("meta.extPath") . "babs/" . dechex($buildId),
            "description" => $desc = "Created $buildClassName build #$buildNumber (&$buildId): "
            . count($messages) > 0 ? implode(", ", $messages) : "lint passed",
            "context" => "poggit-ci/$project->name"
        ], RepoWebhookHandler::$token);
        echo $statusData["context"] . ": " . $statusData["description"] . ", " . $statusData["state"] . " - " . $statusData["target_url"] . "\n";
    }

    public abstract function getName(): string;

    public abstract function getVersion(): string;

    protected abstract function build(Phar $phar, RepoZipball $zipball, WebhookProjectModel $project): BuildResult;

    protected function checkPhp(BuildResult $result, string $iteratedFile, string $contents, bool $isFileMain) {
        $lines = explode("\n", $contents);
        $tokens = token_get_all($contents);
        $currentLine = 1;
        $namespaces = [];
        $classes = [];
        $wantClass = false;
        $currentNamespace = "";
        foreach($tokens as $t) {
            if(!is_array($t)) {
                $t = [-1, $t, $currentLine];
            }
            $lastToken = $token ?? [0, "", 0];
            list($tokenId, $currentCode, $currentLine) = $token = $t;
            $currentLine += substr_count($currentCode, "\n");

            if($tokenId === T_WHITESPACE) continue;
            if($tokenId === T_STRING) {
                if(isset($buildingNamespace)) {
                    $buildingNamespace .= trim($currentCode);
                } elseif($wantClass) {
                    $classes[] = [$currentNamespace, trim($currentCode), $currentLine];
                    $wantClass = false;
                }
            } elseif($tokenId === T_NS_SEPARATOR) {
                if(isset($buildingNamespace)) {
                    $buildingNamespace .= trim($currentCode);
                }
            } elseif($tokenId === T_CLASS) {
                if($lastToken[0] !== T_PAAMAYIM_NEKUDOTAYIM) $wantClass = true;
            } elseif($tokenId === T_NAMESPACE) {
                $buildingNamespace = "";
            } elseif($tokenId === -1) {
                if(trim($currentCode) === ";" || trim($currentCode) === "{" and isset($buildingNamespace)) {
                    $namespaces[] = $currentNamespace = $buildingNamespace;
                    unset($buildingNamespace);
                }
            } elseif($tokenId === T_CLOSE_TAG) {
                $status = new CloseTagLint();
                $status->file = $iteratedFile;
                $status->line = $currentLine;
                $status->code = $lines[$currentLine - 1] ?? "";
                $status->hlSects[] = [$closeTagPos = strpos($status->code, "?>"), $closeTagPos + 2];
                $result->addStatus($status);
            } elseif($tokenId === T_INLINE_HTML or $tokenId === T_ECHO) {
                if($tokenId === T_INLINE_HTML) {
                    if(isset($hasReportedInlineHtml)) continue;
                    $hasReportedInlineHtml = true;
                }
                if($tokenId === T_ECHO) {
                    if(isset($hasReportedUseOfEcho)) continue;
                    $hasReportedUseOfEcho = true;
                }
                $status = new DirectStdoutLint();
                $status->file = $iteratedFile;
                $status->line = $currentLine;
                if($tokenId === T_INLINE_HTML) {
                    $status->code = $currentCode;
                    $status->isHtml = true;
                } else {
                    $status->code = $lines[$currentLine - 1] ?? "";
                    $status->hlSects = [$hlSectsPos = stripos($status->code, "echo"), $hlSectsPos + 2];
                    $status->isHtml = false;
                }
                $status->isFileMain = $isFileMain;
                $result->addStatus($status);
            }
        }
        foreach($classes as list($namespace, $class, $line)) {
            if($iteratedFile !== "src/" . str_replace("\\", "/", $namespace) . "/" . $class . ".php") {
                $status = new NonPsrLint();
                $status->file = $iteratedFile;
                $status->line = $line;
                $status->code = $lines[$line - 1] ?? "";
                $status->hlSects = [$classPos = strpos($status->code, $class), $classPos + 2];
                $status->class = $namespace . "\\" . $class;
                $result->addStatus($status);
            }
        }
    }
}