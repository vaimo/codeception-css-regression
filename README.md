css-regression
==============
CSS Regression tests in Codeception

Install
-------
```shell
composer require mendicm/css-regression
```

Configure
---------
```yaml
modules:
    enabled:
        - WebDriver:
            ...
        - \mendicm\CodeceptionCssRegression\Module\CssRegression:
            referenceImageDirectory: 'referenceImages'
            failImageDirectory: 'failImages'
            maxDifference: 0.1
            automaticCleanup: true
            module: WebDriver
            fullScreenshots: false
```


Usage
-----
```php
$I->amOnPage('/');
$I->hideElements('.socialMediaButton');
$I->seeNoDifferenceToReferenceImage('NewsArticle', '#news-article');
```

