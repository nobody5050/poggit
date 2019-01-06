Poggitnode
===

[Discord ![](https://img.shields.io/discord/402639859535052811.svg)](https://discord.gg/NgHf9jt)

## What is this?
Poggitnode is a website for two things: a plugin builder (Poggitnode-CI) and a plugin list (Poggitnode-Release).

## Features
### Poggitnode-CI: plugin builder
Poggit will build js files for your project when you push a commit or make a pull request.

Login on the [Poggitnode website](https://example.io) and authorize the Poggit application for your user account or your organizations. You can find buttons to enable Poggit-CI for particular repos at [https://example.io/ci](the CI admin panel). Poggit will help you create the file `.poggitnode.yml` in your repo, and then Poggitnode will start building your projects in your repo every commit.

### Poggitnode-Release: plugin list
A project can be released after it has a development build. You can find the release button in the CI project page.

You can find a list of released plugins at https://example.io. You can also find plugins pending review at https://example.io.

### Virions
poggitnode does not have virons however this is a planned feature

## Planned Poggitnode features
* Generate docs for plugins
* Power an online translation platform for plugins
* Manage a plugin requests list

## Can I host it myself?
Yes, technically you can, although discouraged.

Poggitnode manages a website that allows users to find plugins. Therefore, if everyone creates their own Poggitnode website, it will be confusing for users as plugin lists are scattered everywhere. For the sake of the community, unless you have improved your version of Poggitnode so much that the original version is no longer worth existing, please don't host a public Poggitnode yourself.

However, for various reasons, mainly that I am a stubborn Free Software supporter, you can still host a Poggitnode instance yourself. This project is licensed under the Apache license, Version 2.0. You can obtain a full copy of this license at the [LICENSE](LICENSE) file.

Nevertheless, Poggitnode is open-sourced for developers, not businesses. It is here for developers to improve it, or to learn from it, _"to build software better, **together**"_. You are welcome if you want to host Poggitnode yourself for security reasons, or for testing. But if you are hosting Poggitnode for profit reasons, **I politely ask you not to do that**.

## Installation
**Please** read [_Can I host it myself?_](#can-i-host-it-myself) before installing Poggitnode.

Then, refer to [INSTALL.md](INSTALL.md) for instructions to install Poggitnode.
