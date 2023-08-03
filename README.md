# silverstripe-enhanced-requirements

Extends and enhances the Silverstripe requirements system, providing inlining of assets, http/2 server push, etc.

_Note: This module is designed to provide a number of tools which may help to improve the front-end performance of a website.  It is not an 'off-the-shelf' solution, and should be used carefully.  Just setting everything to preload or push, or inlining content without first analysing where and how it's used can lead to a reduction in performance!_

## Requirements
*Silverstripe 4.x

## Installation
Install with `composer require dorsetdigital/silverstripe-enhanced-requirements`

Once installed, the module will set the Requirements system to use the enhanced backend, but none of the additional features will be enabled by default. 

## Usage

The module extends the standard `Requirements` methods, providing additional options.  Currently, these additional options are available for both CSS and JS files:

- inline
- preload
- push
- nonce

The following option is also available for CSS files:

- defer


### inline

When this option is set, the framework will attempt to add the provided CSS or JS file as inline code rather than being loaded externally.   This allows developers to still build front-end assets with existing workflows, but inline the processed content where it is advantageous to do so:
```
Requirements::css('build/app.css', 'screen', ['inline' => true]);
```

```
Requirements::javascript('build/app.js', ['inline' => true]);
```

### preload

The preload option automatically adds `<link rel="preload">` tags to the markup to help improve the load order of important assets

```
Requirements::javascript('build/critical.js', ['preload' => true]);
```
This would result in the following tag being added to the markup:
```
<link rel="preload" as="script" type="application/javascript" href="/build/critical.js?m=1623314562">
```

### push

The push option adds HTML link headers to the response.  On systems where this is supported, this will trigger a server push of the specified assets in order for them to be delivered even before the browser has completed parsing the document.

```
Requirements::css('build/bundle.css', 'screen', ['push' => true]);
```

Would result in an HTTP header similar to the following being added to the response:

```	
Link </build/bundle.css?m=1623314562>; rel=preload; as=style 
```

### nonce

This adds the HTML 'nonce' attribute as required.  Can be useful for dealing with CSP implementations


### defer (css)

If the defer option is added to a CSS inclusion, a tag will be injected into the head of the document which loads the specified CSS file after the page has loaded.  This can help to reduce page blocking for styles which are not needed for the initial rendering.
In order to provide support for browsers which do not run javascript, a noscript tag is also added.
The resultant output looks like this:

```
<link rel="preload" href="/path-to-file.css?m=1690972760" as="style" onload="this.onload=null;this.rel="stylesheet" />
<noscript><link rel="stylesheet" href="/path-to-file.css?m=1690972760" /></noscript>
```


## Tag ordering

The module does not change the ordering of tags added via the `Requirements` API, nor does it change the signatures of the default class methods (with the exception of adding a third parameter to the themedCSS() and themedJavascript() methods) so should be a drop-in replacement for existing code.

The preload tags are added to the page using the `addHeadTags()` method.  By default, these are added at the bottom of the `<head>` section _after_ the CSS files have been added.  This behaviour may not always be desireable.  If this is the case, the module can be configured to inject any custom tags _before_ the styles / scripts are added.
Since this will affect any custom tags which have been added, not just the preload tags, the result of changing this option should be tested thoroughly before deploying to production, in case of unwanted side-effects.

To set the custom tags to be injected first, use the following in the site's yml config files:

``` 
DorsetDigital\EnhancedRequirements\View\Enhanced_Backend:
  custom_tags_first: true
```
