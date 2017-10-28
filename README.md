Codeception Regression Test module
==================================

Allows base image creation and element output comparison against the base image in later test runs. The module uses
standard PHP image processing library GD and does not require any additional extensions to be installed in the system.  

Install
-------

```shell
composer require vaimo/codeception-css-regression
```

Configure
---------

```yaml
modules:
    enabled:
        - WebDriver:
            ...
        - Vaimo\CodeceptionCssRegression\Module\CssRegression:
            referenceImageDirectory: 'referenceImages'
            failImageDirectory: 'failImages'
            maxDifference: 0.1
            automaticCleanup: true
            module: WebDriver
            fullScreenshots: false
            diffColor: 'BF00FF'
```

Usage
-----

```php
$I->amOnPage('/');
$I->hideElements('.socialMediaButton');
$I->dontSeeDifferencesWithReferenceImage('#news-article', 'News article');
```
