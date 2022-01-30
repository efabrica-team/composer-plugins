# eFabrica Composer plugin

## Installation
```shell
composer global require efabrica/composer-plugin:dev-main
```

Answer `y` to question:
"Do you trust "efabrica/composer-plugin" to execute code and wish to enable it now? (writes "allow-plugins" to composer.json)"

## Command
This plugin allows you to use additional command:

### extended-outdated
Shows a list of installed packages that have updates available, including their latest version. If possible, it also shows URL to diff and changelog.

#### Usage
In your application root dir run:
```shell
composer extended-outdated 
```

If you have packages installed from sources other than github.com and gitlab.com, you can add them with option `--host-to-type`. Use pipe to separate host and type. For example `--host-to-type="git.example.com|github" --host-to-type="git.example.org|gitlab"`

#### Known limitations
1. Command is trying to find changelog file in vendor's dir. It can't be done if it wasn't created or if it is ignored for export (e.g. in .gitattributes)
2. Command creates wrong urls if the release name is different from the tag name (Github)
3. Command now supports only Github and Gitlab types of urls
4. Command supports only --ignore option for now. Other options will be added soon. For now it behaves like you run `composer outdated --direct --strict`

## Contribution
If you find any issue or you just want to make this plugin better, feel free to contribute.