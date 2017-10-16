css-regression
==============
CSS Regression tests in Codeception

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
```


Usage
-----
```php
$I->amOnPage('/');
$I->hideElements('.socialMediaButton');
$I->dontSeeDifferencesWithReferenceImage('#news-article', 'NewsArticle');
```

