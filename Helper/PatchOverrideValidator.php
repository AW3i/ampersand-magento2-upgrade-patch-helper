<?php

namespace Ampersand\PatchHelper\Helper;

use Ampersand\PatchHelper\Patchfile\Entry as PatchEntry;
use Magento\Framework\Module\FullModuleList;
use Ampersand\PatchHelper\Helper\Functions;
use Magento\Framework\Module\Dir;

class PatchOverrideValidator
{
    const TYPE_PREFERENCE = 'Preference';
    const TYPE_METHOD_PLUGIN = 'Plugin';
    const TYPE_FILE_OVERRIDE = 'Override (phtml/js/html)';
    const TYPE_LAYOUT_OVERRIDE = 'Override/extended (layout xml)';

    /**
     * @var string
     */
    private $vendorFilepath;

    /**
     * @var string
     */
    private $appCodeFilepath;

    /**
     * @var Magento2Instance
     */
    private $m2;

    /**
     * @var array
     */
    private $errors;

    /**
     * @var PatchEntry
     */
    private $patchEntry;

    /**
     * @var FullModuleList
     */
    private $fullModuleList;

    /**
     * @var Dir
     */
    private $moduleReader;

    /**
     * PatchOverrideValidator constructor.
     * @param Magento2Instance $m2
     * @param PatchEntry $patchEntry
     * @param FullModuleList $fullModuleList
     */
    public function __construct(Magento2Instance $m2, PatchEntry $patchEntry)
    {
        $this->m2 = $m2;
        $this->patchEntry = $patchEntry;
        $this->vendorFilepath = $this->patchEntry->getPath();
        $this->appCodeFilepath = $this->getAppCodePathFromVendorPath($this->vendorFilepath);
        $this->errors = [
            self::TYPE_FILE_OVERRIDE => [],
            self::TYPE_LAYOUT_OVERRIDE => [],
            self::TYPE_PREFERENCE => [],
            self::TYPE_METHOD_PLUGIN => [],
        ];
        // Initializing objects with the object manager is an antipattern
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->fullModuleList = $objectManager->create('\Magento\Framework\Module\FullModuleList');
        $this->moduleReader = $objectManager->create('\Magento\Framework\Module\Dir');
    }

    /**
     * Returns true only if the file can be validated
     * Currently, only php, phtml and js files in modules are supported
     *
     * @return bool
     */
    public function canValidate()
    {
        $file = $this->vendorFilepath;

        if (Functions::str_contains($file, '/Test/')) {
            return false;
        }
        if (Functions::str_contains($file, '/tests/')) {
            return false;
        }
        if (Functions::str_contains($file, '/dev/tools/')) {
            return false;
        }

        //TODO validate additional files
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        $validExtension = in_array($extension, [
            'html',
            'phtml',
            'php',
            'js',
            'xml'
        ]);

        if ($validExtension && $extension === 'xml') {
            if (Functions::str_contains($file, '/etc/')) {
                return false;
            }
            if (Functions::str_contains($file, '/ui_component/')) {
                return false; //todo could these be checked?
            }
        }

        //TODO validate magento dependencies like dotmailer?
        $modules = $this->fullModuleList->getNames();
        // var_dump($modules);
        // var_dump($modules);
        $modulesToExamine = [];
        foreach ($modules as $module) {
            // $modulesToExamine[] = strstr($this->moduleReader->getDir($module), 'vendor');
            $modulesToExamine[] = $this->moduleReader->getDir($module);
        }
        // var_dump($modulesToExamine);

        $validModule = true;
        foreach ($modulesToExamine as $moduleToExamine) {
            // Doesn't allow third party plugin checks eh?
            // if (Functions::str_starts_with($file, $moduleToExamine)) {
                // $validModule = true;
                // var_dump($moduleToExamine);
            // }
        }

        return ($validExtension && $validModule);
    }

    /**
     * @return $this
     */
    public function validate()
    {
        // var_dump($this->vendorFilepath);
        // var_dump($this->appCodeFilepath);
        // var_dump($this->vendorFilepath, PATHINFO_EXTENSION);
        switch (pathinfo($this->vendorFilepath, PATHINFO_EXTENSION)) {
            case 'php':
                $this->validatePhpFileForPreferences();
                $this->validatePhpFileForPlugins();
                break;
            case 'js':
                $this->validateFrontendFile('static');
                break;
            case 'phtml':
                $this->validateFrontendFile('template');
                break;
            case 'html':
                $this->validateWebTemplateHtml();
                break;
            case 'xml':
                $this->validateLayoutFile();
                break;
            default:
                throw new \LogicException("An unknown file path was encountered $this->vendorFilepath");
                break;
        }

        return $this;
    }

    /**
     * @return array
     */
    public function getErrors()
    {
        return array_filter($this->errors);
    }

    /**
     * Use the object manager to check for preferences
     */
    private function validatePhpFileForPreferences()
    {
        $file = $this->appCodeFilepath;

        $class = ltrim($file, 'app/code/');
        $class = preg_replace('/\\.[^.\\s]{3,4}$/', '', $class);
        $class = str_replace('/', '\\', $class);

        $preferences = [];

        $areaConfig = $this->m2->getAreaConfig();
        foreach (array_keys($areaConfig) as $area) {
            if (isset($areaConfig[$area]['preferences'][$class])) {
                $preference = $areaConfig[$area]['preferences'][$class];
                if ($this->isThirdPartyPreference($class, $preference)) {
                    $preferences[] = $preference;
                }
            }
        }

        // Use raw framework
        $preference = $this->m2->getConfig()->getPreference($class);
        if ($this->isThirdPartyPreference($class, $preference)) {
            $preferences[] = $preference;
        }

        $preferences = array_unique($preferences);

        foreach ($preferences as $preference) {
            $this->errors[self::TYPE_PREFERENCE][] = $preference;
        }
    }

    /**
     * Check for plugins on modified methods within this class
     */
    private function validatePhpFileForPlugins()
    {
        $file = $this->vendorFilepath;
        // var_dump($this->vendorFilepath);

        // namespace regex ^(\s)*namespace(\s)+[a-zA-Z0-9\\].+;$/m
        // probably resource heavy? opening all the files n stuff
        //
        $contents = file_get_contents($file);
        preg_match('/^(\s)*namespace(\s)+[a-zA-Z0-9\\\\].+;$/m', $contents, $matches);
        $namespace = $matches[0];
        $namespace = preg_replace('/^(\s)*namespace(\s)+/m', '', $namespace);
        $namespace = preg_replace('/;$/m', '', $namespace);
        $namespace = $namespace . '\\' . basename($file, '.php');
        var_dump($namespace);
        // var_dump($matches);
        // var_dump($file);
        // hax way of getting namespace
        $class = ltrim($file, 'app/code/');
        // var_dump('Class 1: ' . $class);
        $class = preg_replace('/\\.[^.\\s]{3,4}$/', '', $class);
        // var_dump('Class 2: ' . $class);
        $class = str_replace('/', '\\', $class);
        // var_dump('Class 3: ' . $class);
        $class = $namespace;

        /*
         * Collect a list of non-magento plugins on the given class
         */
        $nonMagentoPlugins = [];

        $areaConfig = $this->m2->getAreaConfig();
        // here is the bug apparent the namespace \Mageplaza\Betterpopup is read as \Magento\Vendor\Mageplaza
        foreach (array_keys($areaConfig) as $area) {
            $tmpClass = $class;
            if (!isset($areaConfig[$area][$tmpClass]['plugins'])) {
                //Search with and without the preceding slash
                $tmpClass = "\\$tmpClass";
                // Displays only vendor plugins
                // Also displays the namespaces with Magento\\Vendor appended
                var_dump('Should match '.$tmpClass);
// above output string(57) "\Magento\Vendor\mageplaza\module-better-popup\Block\Popup"
            }
            // var_dump(array_keys($areaConfig[$area]));
  // string(34) "\Mageplaza\BetterPopup\Block\Popup"
            if (isset($areaConfig[$area][$tmpClass]['plugins'])) {
                foreach ($areaConfig[$area][$tmpClass]['plugins'] as $pluginName => $pluginConf) {
                    if (isset($pluginConf['disabled']) && $pluginConf['disabled']) {
                        continue;
                    }
                    var_dump('PluginName:' . $pluginName);
                    $pluginClass = $pluginConf['instance'];
                    $pluginClass = ltrim($pluginClass, '\\');
                    var_dump('PluginClass: '. $pluginClass);
                    if (!Functions::str_starts_with($pluginClass, 'Magento')) {
                        $nonMagentoPlugins[$pluginClass] = $pluginClass;
                    }
                }
            }
        }

        // Edw mas petaei
        var_dump('edw');
        var_dump(empty($nonMagentoPlugins));
        if (empty($nonMagentoPlugins)) {
            return;
        }

        /*
         * For this patch entry under examination, get a list of all public functions which could be intercepted
         */
        $affectedInterceptableMethods = $this->patchEntry->getAffectedInterceptablePhpFunctions();
        if (empty($affectedInterceptableMethods)) {
            var_dump('feugeis');
            return;
        }

        foreach ($nonMagentoPlugins as $plugin) {
            /*
             * Gather the list of interception methods in this plugin
             */
            $methodsIntercepted = [];
            foreach (get_class_methods($plugin) as $method) {
                var_dump($method);
                if (Functions::str_starts_with($method, 'before')) {
                    $methodName = strtolower(substr($method, 6));
                    var_dump('Edw ti ginetai ' . $methodName);
                    if (!isset($methodsIntercepted[$methodName])) {
                        $methodsIntercepted[$methodName] = [];
                    }
                    $methodsIntercepted[$methodName][] = $method;
                    continue;
                }
                if (Functions::str_starts_with($method, 'after')) {
                    $methodName = strtolower(substr($method, 5));
                    if (!isset($methodsIntercepted[$methodName])) {
                        $methodsIntercepted[$methodName] = [];
                    }
                    $methodsIntercepted[$methodName][] = $method;
                    continue;
                }
                if (Functions::str_starts_with($method, 'around')) {
                    $methodName = strtolower(substr($method, 6));
                    if (!isset($methodsIntercepted[$methodName])) {
                        $methodsIntercepted[$methodName] = [];
                    }
                    $methodsIntercepted[$methodName][] = $method;
                    continue;
                }
            }

            /*
             * Cross reference them with the methods affected in the patch, if there's an intersection the patch
             * has updated a public method which has a plugin against it
             */
            $intersection = array_intersect_key($methodsIntercepted, $affectedInterceptableMethods);

            if (!empty($intersection)) {
                foreach ($intersection as $methods) {
                    foreach ($methods as $method) {
                        $this->errors[self::TYPE_METHOD_PLUGIN][] = "$plugin::$method";
                    }
                }
            }
        }
    }

    /**
     * @param $class
     * @param $preference
     * @return bool
     */
    private function isThirdPartyPreference($class, $preference)
    {
        if ($preference === $class || $preference === "$class\\Interceptor") {
            // Class is not overridden
            return false;
        }

        try {
            $refClass = new \ReflectionClass($preference);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("Could not instantiate $preference (virtualType?)");
        }
        $path = realpath($refClass->getFileName());
        // var_dump($path);

        $pathsToIgnore = [
            '/vendor/magento/',
            '/generated/code/Magento/',
            '/generation/Magento/',
            '/setup/src/Magento/'
        ];

        foreach ($pathsToIgnore as $pathToIgnore) {
            if (Functions::str_contains($path, $pathToIgnore)) {
                // Class is overridden by magento itself, ignore
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $type
     * @throws \Exception
     */
    private function validateFrontendFile($type)
    {
        $file = $this->appCodeFilepath;

        if (Functions::str_ends_with($file, 'requirejs-config.js')) {
            return; //todo review this
        }

        $parts = explode('/', $file);
        $area = (strpos($file, '/adminhtml/') !== false) ? 'adminhtml' : 'frontend';
        $module = $parts[2] . '_' . $parts[3];
        $key = $type === 'static' ? '/web/' : '/templates/';
        $name = str_replace($key, '', strstr($file, $key));
        $path = $this->m2->getMinificationResolver()->resolve($type, $name, $area, $this->m2->getCurrentTheme(), null, $module);

        if (!is_file($path)) {
            throw new \InvalidArgumentException("Could not resolve $file (attempted to resolve to $path)");
        }
        if ($path && strpos($path, '/vendor/magento/') === false) {
            $this->errors[self::TYPE_FILE_OVERRIDE][] = $path;
        }
    }

    /**
     * Knockout html files live in web directory
     */
    private function validateWebTemplateHtml()
    {
        $file = $this->appCodeFilepath;
        $parts = explode('/', $file);
        $module = $parts[2] . '_' . $parts[3];

        /**
         * @link https://github.com/AmpersandHQ/ampersand-magento2-upgrade-patch-helper/issues/1#issuecomment-444599616
         */
        $templatePart = ltrim(preg_replace('#^.+/web/templates?/#i', '', $file), '/');

        $potentialOverrides = array_filter($this->m2->getListOfHtmlFiles(), function ($potentialFilePath) use ($module, $templatePart) {
            $validFile = true;

            if (!Functions::str_ends_with($potentialFilePath, $templatePart)) {
                // This is not the same file name as our layout file
                $validFile = false;
            }
            if (!Functions::str_contains($potentialFilePath, $module)) {
                // This file path does not contain the module name, so not an override
                $validFile = false;
            }
            if (!Functions::str_contains($potentialFilePath, 'vendor/magento/')) {
                // This file path is a magento core override, not looking at core<->core modifications
                $validFile = false;
            }
            return $validFile;
        });

        foreach ($potentialOverrides as $override) {
            $this->errors[self::TYPE_FILE_OVERRIDE][] = $override;
        }
    }

    /**
     * Search the app and vendor directory for layout files with the same name, for the same module.
     */
    private function validateLayoutFile()
    {
        $file = $this->appCodeFilepath;
        $parts = explode('/', $file);
        $area = (Functions::str_contains($file, '/adminhtml/')) ? 'adminhtml' : 'frontend';
        $module = $parts[2] . '_' . $parts[3];

        $layoutFile = end($parts);

        $potentialOverrides = array_filter($this->m2->getListOfXmlFiles(), function ($potentialFilePath) use ($module, $area, $layoutFile) {
            $validFile = true;

            if (!Functions::str_contains($potentialFilePath, $area)) {
                // This is not in the same area
                $validFile = false;
            }
            if (!Functions::str_ends_with($potentialFilePath, $layoutFile)) {
                // This is not the same file name as our layout file
                $validFile = false;
            }
            if (!Functions::str_contains($potentialFilePath, $module)) {
                // This file path does not contain the module name, so not an override
                $validFile = false;
            }
            if (Functions::str_contains($potentialFilePath, 'vendor/magento/')) {
                // This file path is a magento core override, not looking at core<->core modifications
                $validFile = false;
            }
            return $validFile;
        });

        foreach ($potentialOverrides as $override) {
            $this->errors[self::TYPE_FILE_OVERRIDE][] = $override;
        }
    }

    /**
     * @param string $path
     * @return string
     */
    private function getAppCodePathFromVendorPath($path)
    {
        $path = str_replace('vendor/magento/', '', $path);
        $parts = explode('/', $path);

        $module = '';
        foreach (explode('-', str_replace('module-', '', $parts[0])) as $value) {
            $module .= ucfirst(strtolower($value));
        }

        return str_replace("{$parts[0]}/", "app/code/Magento/$module/", $path);
    }
}
