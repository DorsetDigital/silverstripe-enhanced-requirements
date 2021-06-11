<?php

namespace DorsetDigital\EnhancedRequirements\View;

use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\Dev\Deprecation;
use SilverStripe\View\HTML;
use SilverStripe\View\Requirements;
use SilverStripe\View\Requirements_Backend;
use SilverStripe\View\SSViewer;
use SilverStripe\View\ThemeResourceLoader;

class Enhanced_Backend extends Requirements_Backend
{
    use Configurable;

    /**
     * @config
     * @var bool
     */
    private static $custom_tags_first = false;

    /**
     * @var array
     */
    private $preload = [];


    /**
     * Update the given HTML content with the appropriate include tags for the registered
     * requirements. Needs to receive a valid HTML/XHTML template in the $content parameter,
     * including a head and body tag.
     *
     * @param string $content HTML content that has already been parsed from the $templateFile
     *                             through {@link SSViewer}
     * @return string HTML content augmented with the requirements tags
     */
    public function includeInHTML($content)
    {
        if (func_num_args() > 1) {
            Deprecation::notice(
                '5.0',
                '$templateFile argument is deprecated. includeInHTML takes a sole $content parameter now.'
            );
            $content = func_get_arg(1);
        }

        // Skip if content isn't injectable, or there is nothing to inject
        $tagsAvailable = preg_match('#</head\b#', $content);
        $hasFiles = $this->css || $this->javascript || $this->customCSS || $this->customScript || $this->customHeadTags;
        if (!$tagsAvailable || !$hasFiles) {
            return $content;
        }
        $requirements = '';
        $jsRequirements = '';
        $customTagsFirst = self::config()->get('custom_tags_first');

        if ($customTagsFirst) {
            foreach ($this->getCustomHeadTags() as $customHeadTag) {
                $requirements .= "{$customHeadTag}\n";
            }
        }


        // Combine files - updates $this->javascript and $this->css
        $this->processCombinedFiles();

        // Script tags for js links
        foreach ($this->getJavascript() as $file => $attributes) {
            // Build html attributes
            $htmlAttributes = [
                'type' => isset($attributes['type']) ? $attributes['type'] : "application/javascript",
                'src' => $this->pathForFile($file),
            ];
            if (!empty($attributes['async'])) {
                $htmlAttributes['async'] = 'async';
            }
            if (!empty($attributes['defer'])) {
                $htmlAttributes['defer'] = 'defer';
            }
            if (!empty($attributes['integrity'])) {
                $htmlAttributes['integrity'] = $attributes['integrity'];
            }
            if (!empty($attributes['crossorigin'])) {
                $htmlAttributes['crossorigin'] = $attributes['crossorigin'];
            }
            $jsRequirements .= HTML::createTag('script', $htmlAttributes);
            $jsRequirements .= "\n";
        }

        // Add all inline JavaScript *after* including external files they might rely on
        foreach ($this->getCustomScripts() as $script) {
            $jsRequirements .= HTML::createTag(
                'script',
                ['type' => 'application/javascript'],
                "//<![CDATA[\n{$script}\n//]]>"
            );
            $jsRequirements .= "\n";
        }

        // CSS file links
        foreach ($this->getCSS() as $file => $params) {
            $htmlAttributes = [
                'rel' => 'stylesheet',
                'type' => 'text/css',
                'href' => $this->pathForFile($file),
            ];
            if (!empty($params['media'])) {
                $htmlAttributes['media'] = $params['media'];
            }
            if (!empty($params['integrity'])) {
                $htmlAttributes['integrity'] = $params['integrity'];
            }
            if (!empty($params['crossorigin'])) {
                $htmlAttributes['crossorigin'] = $params['crossorigin'];
            }
            $requirements .= HTML::createTag('link', $htmlAttributes);
            $requirements .= "\n";
        }

        // Literal custom CSS content
        foreach ($this->getCustomCSS() as $css) {
            $requirements .= HTML::createTag('style', ['type' => 'text/css'], "\n{$css}\n");
            $requirements .= "\n";
        }

        if ($customTagsFirst !== true) {
            foreach ($this->getCustomHeadTags() as $customHeadTag) {
                $requirements .= "{$customHeadTag}\n";
            }
        }

        // Inject CSS  into body
        $content = $this->insertTagsIntoHead($requirements, $content);

        // Inject scripts
        if ($this->getForceJSToBottom()) {
            $content = $this->insertScriptsAtBottom($jsRequirements, $content);
        } elseif ($this->getWriteJavascriptToBody()) {
            $content = $this->insertScriptsIntoBody($jsRequirements, $content);
        } else {
            $content = $this->insertTagsIntoHead($jsRequirements, $content);
        }

        $this->addPreloadHeaders();

        return $content;
    }

    /**
     * @return void
     */
    private function addPreloadHeaders(): void
    {
        $assets = $this->preload;
        if (count($assets) > 0) {
            $headerParts = [];
            foreach ($assets as $asset) {
                $headerParts[] = '<' . $this->pathForFile($asset['file']) . '>; rel=preload; as=' . $asset['type'];
            }
            $response = Controller::curr()->getResponse();
            $response->addHeader('Link', implode(',', $headerParts));
        }
    }

    public function deleteAllCombinedFiles()
    {
        $combinedFolder = $this->getCombinedFilesFolder();
        $assetHandler = $this->getAssetHandler();
        if ($combinedFolder && $assetHandler) {
            $assetHandler->removeContent($combinedFolder);
        }
    }

    /**
     * Registers the given themeable stylesheet as required.
     *
     * A CSS file in the current theme path name 'themename/css/$name.css' is first searched for,
     * and it that doesn't exist and the module parameter is set then a CSS file with that name in
     * the module is used.
     *
     * @param string $name The name of the file - eg '/css/File.css' would have the name 'File'
     * @param null $media Comma-separated list of media types to use in the link tag
     *                       (e.g. 'screen,projector')
     * @param array $options
     */
    public function themedCSS($name, $media = null, array $options = [])
    {
        $path = ThemeResourceLoader::inst()->findThemedCSS($name, SSViewer::get_themes());
        if ($path) {
            $this->css($path, $media, $options);
        } else {
            throw new InvalidArgumentException(
                "The css file doesn't exist. Please check if the file $name.css exists in any context or search for "
                . "themedCSS references calling this file in your templates."
            );
        }
    }

    /**
     * Registers the given themeable javascript as required.
     *
     * A javascript file in the current theme path name 'themename/javascript/$name.js' is first searched for,
     * and it that doesn't exist and the module parameter is set then a javascript file with that name in
     * the module is used.
     *
     * @param string $name The name of the file - eg '/js/File.js' would have the name 'File'
     * @param null $type Comma-separated list of types to use in the script tag
     *                       (e.g. 'text/javascript,text/ecmascript')
     * @param array $options
     */
    public function themedJavascript($name, $type = null, array $options = [])
    {
        $path = ThemeResourceLoader::inst()->findThemedJavascript($name, SSViewer::get_themes());
        if ($path) {
            $opts = [];
            if ($type) {
                $opts['type'] = $type;
            }
            $this->javascript($path, array_merg($opts, $options));
        } else {
            throw new InvalidArgumentException(
                "The javascript file doesn't exist. Please check if the file $name.js exists in any "
                . "context or search for themedJavascript references calling this file in your templates."
            );
        }
    }

    /**
     * @param $file
     */
    private function inlineCSS($file): void
    {
        if (preg_match('{^(//)|(http[s]?:)}', $file) || Director::is_root_relative_url($file)) {
            //We can't inline this.. just add it to the stack without the inline options
            $this->css($file);
        } else {
            $path = Director::getAbsFile(ModuleResourceLoader::singleton()->resolvePath($file));
            if (is_file($path)) {
                Requirements::customCSS(file_get_contents($path));
            }
        }
    }

    /**
     * Register the given stylesheet into the list of requirements.
     *
     * @param string $file The CSS file to load, relative to site root
     * @param string $media Comma-separated list of media types to use in the link tag
     *                      (e.g. 'screen,projector')
     * @param array $options List of options. Available options include:
     * - 'integrity' : SubResource Integrity hash
     * - 'crossorigin' : Cross-origin policy for the resource
     * - 'preload' : (boolean) Add preload headers
     * - 'inline' : (boolean) Include this asset inline instead of loading
     * - 'push' : (boolean) add http headers to initiate http/2 push
     */
    public function css($file, $media = null, $options = [])
    {
        $file = ModuleResourceLoader::singleton()->resolvePath($file);

        $inline = $options['inline'] ?? null;
        if ($file && ($inline === true)) {
            $this->inlineCSS($file);
        } else {
            $integrity = $options['integrity'] ?? null;
            $crossorigin = $options['crossorigin'] ?? null;
            $preload = $options['preload'] ?? null;
            $push = $options['push'] ?? null;

            $this->css[$file] = [
                "media" => $media,
                "integrity" => $integrity,
                "crossorigin" => $crossorigin
            ];

            if ($preload === true) {
                $plTag = HTML::createTag('link', [
                    'rel' => 'preload',
                    'as' => 'style',
                    'type' => 'text/css',
                    'href' => $this->pathForFile($file),
                    'crossorigin' => ''
                ]);
                self::insertHeadTags($plTag);
            }

            if ($push === true) {
                //Add to the preload
                $this->preload[] = [
                    'file' => $file,
                    'type' => 'style'
                ];
            }

        }
    }

    /**
     * Adds a script to the page inline
     * @param $file
     */
    private function inlineJS($file)
    {
        if (preg_match('{^(//)|(http[s]?:)}', $file) || Director::is_root_relative_url($file)) {
            //We can't inline this.. just add it to the stack without the inline options
            $this->javascript($file);
        } else {
            $path = Director::getAbsFile(ModuleResourceLoader::singleton()->resolvePath($file));
            if (is_file($path)) {
                Requirements::customScript(file_get_contents($path));
            }
        }
    }

    /**
     * Register the given JavaScript file as required.
     *
     * @param string $file Either relative to docroot or in the form "vendor/package:resource"
     * @param array $options List of options. Available options include:
     * - 'provides' : List of scripts files included in this file
     * - 'async' : Boolean value to set async attribute to script tag
     * - 'defer' : Boolean value to set defer attribute to script tag
     * - 'type' : Override script type= value.
     * - 'integrity' : SubResource Integrity hash
     * - 'crossorigin' : Cross-origin policy for the resource
     * - 'preload' : (boolean) Add preload tags
     * - 'inline' : (boolean) Include this asset inline instead of loading
     * - 'push' : (boolean) add http headers to initiate http/2 push
     */
    public function javascript($file, $options = [])
    {
        $file = ModuleResourceLoader::singleton()->resolvePath($file);

        $inline = $options['inline'] ?? null;
        if ($file && ($inline === true)) {
            $this->inlineJS($file);
        } else {
            // Get type
            $type = null;
            if (isset($this->javascript[$file]['type'])) {
                $type = $this->javascript[$file]['type'];
            }
            if (isset($options['type'])) {
                $type = $options['type'];
            }

            // make sure that async/defer is set if it is set once even if file is included multiple times
            $async = (
                isset($options['async']) && $options['async']
                || (
                    isset($this->javascript[$file])
                    && isset($this->javascript[$file]['async'])
                    && $this->javascript[$file]['async']
                )
            );
            $defer = (
                isset($options['defer']) && $options['defer']
                || (
                    isset($this->javascript[$file])
                    && isset($this->javascript[$file]['defer'])
                    && $this->javascript[$file]['defer']
                )
            );
            $integrity = $options['integrity'] ?? null;
            $crossorigin = $options['crossorigin'] ?? null;
            $preload = $options['preload'] ?? null;
            $push = $options['push'] ?? null;

            $this->javascript[$file] = [
                'async' => $async,
                'defer' => $defer,
                'type' => $type,
                'integrity' => $integrity,
                'crossorigin' => $crossorigin
            ];

            if ($preload === true) {
                $plTag = HTML::createTag('link', [
                    'rel' => 'preload',
                    'as' => 'script',
                    'type' => 'application/javascript',
                    'href' => $this->pathForFile($file),
                    'crossorigin' => ''
                ]);
                self::insertHeadTags($plTag);
            }

            if ($push === true) {
                //Add to the preload
                $this->preload[] = [
                    'file' => $file,
                    'type' => 'script'
                ];
            }

            // Record scripts included in this file
            if (isset($options['provides'])) {
                $this->providedJavascript[$file] = array_values($options['provides']);
            }
        }

    }

}
