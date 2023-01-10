<?php

/**
 * ochHelper
 *
 * @version     ___VERSION_OCHHELPER___
 * @package     Joomla
 *
 * @author      ___AUTHOR___
 * @copyright   ___COPYRIGHT_NS___
 * @license     ___LICENSE___
 * @link        ___LINK___
 */

namespace ___NAMESPACE_HELPER___;

// No direct access
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;

/**
 * Class OchHelper
 *
 * @since  0.0.0
 */
class OchHelper
{
    /**
     * Truncate and strip the string
     *
     * @param   string   $str       String to truncate
     * @param   integer  $len       Length
     * @param   bool     $strip     Strip code and markup
     * @param   string   $ellipsis  String to use as ellipsis marker
     *
     * @version 20200121
     *
     * @return string
     */
    public static function truncate($str, $len = 0, $strip = false, $ellipsis = '...')
    {
        // 20200121: Using MB_ functions to handle multi-byte language characters
        $result = $str;

        if ($strip) {
            // {tag}text{/tag} or {tag action}text{/tag}
            $result = preg_replace('#{(.*?)}(.*?){\/(.*?)}#s', '', $result);

            // {tag} or {tag action}
            $result = preg_replace('#{(.*?)}#s', '', $result);

            // <script type="....>...</script>
            $result = preg_replace('#<script\b[^>]*>(.*?)<\/script>#is', '', $result);

            // [widgetkit: xyz]
            $result = preg_replace('#\[(.*?)\]#s', '', $result);

            $result = strip_tags($result);
            $result = preg_replace('#\r|\n|\t|&nbsp;#', ' ', $result);
            $result = preg_replace('#(  )#', ' ', $result);
            $result = trim($result);
        }

        if (extension_loaded('mbstring')) {
            if (mb_strlen($result) > $len && $len !== 0) {
                if ($len > mb_strlen($ellipsis)) {
                    $len = $len - mb_strlen($ellipsis);
                }

                $result = mb_substr($result, 0, $len) . $ellipsis;
            }
        } else {
            if (strlen($result) > $len && $len !== 0) {
                if ($len > strlen($ellipsis)) {
                    $len = $len - strlen($ellipsis);
                }

                $result = substr($result, 0, $len) . $ellipsis;
            }
        }

        return $result;
    }

    /**
     * Function to validate the download id
     *
     * @param   string   $url             the validation url
     * @param   string   $element         the element to check the download id for
     * @param   string   $downloadId      the download id to validate
     * @param   boolean  $writeCache      enable writing remote response to cache
     * @param   boolean  $readCache       return response from cache or remote
     * @param   boolean  $message         Display app messages
     * @param   string   $languagePrefix  the language prefix needed to construct app messages
     *
     * @return  mixed  array when display message is false, boolean when display message is true
     */
    public static function validateDownloadId(
        $url,
        $element,
        $downloadId,
        $writeCache = true,
        $readCache = true,
        $message = false,
        $languagePrefix = ''
    ) {
        $app = Factory::getApplication();

        $validationUri = clone Uri::getInstance($url);

        $cache = Factory::getCache('onlinecommunityhub_downloadids', '');
        $cache->setCaching(true);
        $cache->setLifeTime(24 * 60);

        $response   = false;
        $hash       = md5($element);
        $downloadId = trim($downloadId);

        if (is_object($cache) && $readCache === true) {
            // Get response from cache
            $response = $cache->get($hash);
        }

        if (!$response) {
            try {
                $headers = ['token' => md5($downloadId),];
                $validationUri->setVar('task', 'updater.validateKey');
                $validationUri->setvar('downloadId', $downloadId);
                $validationUri->delvar('dummy');

                $validateUrl = $validationUri->toString();

                $response = HttpFactory::getHttp()->get($validateUrl, $headers, 15);

                // Joomla 4.0 issue where $response would not get stored in cache
                $store = new \stdClass();
                $store->code = $response->code;
                $store->body = $response->body;

                if (is_object($cache) && $writeCache) {
                    // Write response to cache
                    $cache->store($store, $hash, 'onlinecommunityhub_downloadids');
                }
            } catch (\Exception $e) {
                $app->enqueueMessage($e->getMessage(), 'error');

                $response = false;
            }
        }

        if (!$message) {
            // Do not queue messages, just return response
            $result = array();
            $result['code'] = $response->code;
            $result['status'] = 0;
            $result['valid_to'] = '';

            if (200 == $response->code) {
                $result = json_decode($response->body, true);
                $tzOffset = new \DateTimeZone(Factory::getConfig()->get('offset'));
                $valid_to = Factory::getDate(strtotime($result['valid_to']));
                $valid_to->setTimeZone($tzOffset);
                $result['valid_to'] = date(Text::_('DATE_FORMAT_LC3'), strtotime((string) $valid_to));
            }

            return $result;
        }

        if ($response && 200 == $response->code) {
            $validationData = json_decode($response->body, true);

            if (is_array($validationData) && array_key_exists('status', $validationData) && array_key_exists('valid_to', $validationData)) {
                switch ($validationData['status']) {
                    case 0:
                        // No Access or Invalid
                        $app->enqueueMessage(Text::_($languagePrefix . '_DOWNLOADID_INVALID_MSG'), 'error');

                        return false;
                        break;

                    case 1:
                        // Active
                        $tzOffset = new \DateTimeZone(Factory::getConfig()->get('offset'));
                        $valid_to = Factory::getDate(strtotime($validationData['valid_to']));
                        $valid_to->setTimeZone($tzOffset);
                        $now = Factory::getDate('now', $tzOffset);

                        // Check if Valid To date is within 1 month
                        if (strtotime((string) $now . '+ 1 month') > strtotime((string) $valid_to)) {
                            $message = Text::sprintf(
                                $languagePrefix . '_DOWNLOADID_ACTIVE_MSG',
                                date(Text::_('DATE_FORMAT_LC3'), strtotime((string) $valid_to))
                            );
                            $app->enqueueMessage($message, 'warning');
                        }

                        return true;
                        break;
                }
            }
        }

        if ($response && 403 == $response->code) {
            $app->enqueueMessage(Text::_($languagePrefix . '_DOWNLOADID_INVALID_MSG'), 'error');

            return false;
        }

        return true;
    }

    /**
     * Function to prepare the plugin package update, to be called by onInstallerBeforePackageDownload
     *
     * @param   object   $package    The package information needed to download the update
     * @param   boolean  $extraData  Add Extra Data to request headers
     *
     * @return object|false on error
     */
    public static function prepareUpdate($package, $extraData = true)
    {
        $app = Factory::getApplication();

        $uri = clone Uri::getInstance($package->url);
        $host = $uri->getHost();

        if ($host !== 'onlinecommunityhub.nl' && $host !== 'och.developmenthub.nl') {
            // We will only handle our own extensions
            return false;
        }

        $element = $uri->getVar('element', '');

        if (empty($element) || $element !== $package->plugin->name) {
            // We will only handle our own extension / element
            return false;
        }

        // If no download key is set
        if (empty($package->downloadId)) {
            $app->enqueueMessage(Text::_($package->languagePrefix . '_DOWNLOADID_MISSING_MSG'), 'notice');

            return false;
        }

        $package->downloadId = trim($package->downloadId);
        $return              = self::validateDownloadId($package->url, $element, $package->downloadId, false, false, true, $package->languagePrefix);

        if ($extraData) {
            $domain = Uri::getInstance()->getHost();

            if ($domain) {
                $package->headers['X-Requesting-Domain'] = $domain;
            }

            $version = new Version();

            if ($version) {
                $package->headers['X-Requesting-Joomlacms-Version'] = (string) $version->getShortVersion();
            }

            if (phpversion()) {
                $package->headers['X-Requesting-Php-Version'] = (string) phpversion();
            }

            $db = Factory::getDbo();

            if ($db->getVersion()) {
                $package->headers['X-Requesting-Db-Version'] = (string) $db->getVersion();
            }
        }

        // Append the download key to the download URL
        $uri->setVar('key', $package->downloadId);
        $package->url = $uri->toString();

        // Append the Extra_query to the update_sites table
        // This will handle updating of disabled plugin
        self::setUpdateExtraQuery($package->plugin->id, $package->downloadId);

        return $package;
    }

    /**
     * Function to set the extra_query in the #__update_sites table
     *
     * @param   string  $pluginId    The plugin Id to add the extra_query to
     * @param   string  $downloadId  The Download Id to add in the extra_query
     *
     * @return void
     */
    public static function setUpdateExtraQuery($pluginId, $downloadId)
    {
        $downloadId = trim($downloadId);
        $extraQuery = $downloadId == '' ? '' : 'key=' . $downloadId;

        $db = Factory::getDbo();

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__update_sites', 'a'))
            ->join('INNER', $db->quoteName('#__update_sites_extensions', 'b')
                . ' ON (' . $db->quoteName('a.update_site_id') . ' = ' . $db->quoteName('b.update_site_id') . ')')
            ->set($db->quoteName('a.extra_query') . ' = ' . $db->quote($extraQuery))
            ->where($db->quoteName('b.extension_id') . ' = ' . $pluginId);

        $db->setQuery($query);
        $results = $db->execute();

        return;
    }

    /**
     * Method to determine if we are on Joomla 3.x
     *
     * @param   string  $client  Determine if we are on administrator or site
     *
     * @return boolean
     */
    public static function isJoomla3($client = '')
    {
        $version = new Version();

        $isJoomla3 = $version::MAJOR_VERSION == 3 ? true : false;

        if (empty($client)) {
            return $isJoomla3;
        }

        return ($isJoomla3 && Factory::getApplication()->isClient($client));
    }

    /**
     * Method to determine if we are on Joomla 4.x
     *
     * @param   string  $client  Determine if we are on administrator or site
     *
     * @return boolean
     */
    public static function isJoomla4($client = '')
    {
        $version = new Version();

        $isJoomla4 = $version::MAJOR_VERSION == 4 ? true : false;

        if (empty($client)) {
            return $isJoomla4;
        }

        return ($isJoomla4 && Factory::getApplication()->isClient($client));
    }

    /**
     * Method to check if we are on a specified joomla version
     *
     * @param   string  $version  the version to check
     * @param   string  $compare  the comparison
     *
     * @since  1.2.0 (20220906)
     * @return boolean
     */
    public static function isJoomlaVersion($version, $compare = '=')
    {
        $joomlaVersion = new Version();

        return version_compare($joomlaVersion->getShortVersion(), $version, $compare);
    }

    /**
     * Adds a linked stylesheet / linked script to the page
     *
     * @param   string  $file     Path to the linked style sheet /linked script
     * @param   array   $options  Array of options. Example: array('version' => 'auto', 'conditional' => 'lt IE 9')
     * @param   array   $attribs  Array of attributes. Example: array('id' => 'stylesheet', 'data-test' => 1)
     *
     * @return  Document instance of $this to allow chaining
     */
    public static function addFile($file = '', $options = [], $attribs = [])
    {
        // Set default options
        $options['relative'] = isset($options['relative']) ? $options['relative'] : false;

        $wa        = self::isJoomla4() ? Factory::getApplication()->getDocument()->getWebAssetManager() : false;
        $pathInfo  = pathinfo($file);
        $assetName = $pathInfo['filename'] . '.' . $pathInfo['extension'];
        $result    = false;

        if (!(stripos($file, 'http://') === 0 || stripos($file, 'https://') === 0 || strpos($file, '//') === 0)) {
            if ($options['relative']) {
                // We use option relative to load the script via http(s), in Joomla API option relative means: relative to media folder
                $urlPath  = str_replace('/administrator', '', Uri::base());
                $loadFile = $urlPath . ltrim($file, '/ ');
            } else {
                $file = ltrim($file, '/');

                if ((isset($options['debug']) && $options['debug']) || JDEBUG) {
                    // We are in debug mode, uncompressed file needed
                    $loadFile = $file;
                } else {
                    $loadFile = ltrim($pathInfo['dirname'] . '/' . $pathInfo['filename'] . '.min.' . $pathInfo['extension'], '/');

                    if (!is_file(JPATH_ROOT . '/' . $loadFile)) {
                        // Minified file doesn't exist, fallback
                        $loadFile = $file;
                    }
                }

                if (isset($options['version'])) {
                    $modify = filemtime(JPATH_ROOT . '/' . $loadFile);

                    if ($modify) {
                        $options['version'] .= '-' . $modify;
                    }
                }
            }
        } else {
            $loadFile = $file;
        }

        if ($pathInfo['extension'] == 'js') {
            $result = HTMLHelper::_('script', $loadFile, $options, $attribs);
            // if ($wa)
            // {
            //  $result = $wa->registerAndUseScript($assetName, $loadFile, $options, $attribs);
            // }
            // else
            // {
            //  $result = HTMLHelper::_('script', $loadFile, $options, $attribs);
            // }
        } elseif ($pathInfo['extension'] == 'css') {
            $result = HTMLHelper::_('stylesheet', $loadFile, $options, $attribs);
            // if ($wa)
            // {
            //  $result = $wa->registerAndUseStyle($assetName, $loadFile, $options, $attribs);
            // }
            // else
            // {
            //  $result = HTMLHelper::_('stylesheet', $loadFile, $options, $attribs);
            // }
        }

        return $result;
    }

    /**
     * Function to get a model (J3 / J4 independent)
     *
     * @param   string  $component  The component to get the model for
     * @param   string  $model      The model to get
     * @param   string  $location   The model location (site / admin)
     * @param   array   $config     The config setting to pass to the model instantiation
     *
     * @since  1.1.0 (20220326)
     * @return object
     */
    public static function getModel($component = 'com_content', $model = 'article', $location = 'site', array $config = ['ignore_request' => true])
    {
        if (self::isJoomla4()) {
            $model = Factory::getApplication()->bootComponent($component)->getMVCFactory()->createModel(ucfirst($model), ucfirst($location), $config);
        } else {
            $path = ($location == 'site') ? JPATH_SITE : JPATH_ADMINISTRATOR;

            BaseDatabaseModel::addIncludePath($path . '/components/' . $component . '/models', 'ContentModel');
            Table::addIncludePath($path . '/components/' . $component . '/tables');
            $model = BaseDatabaseModel::getInstance(ucfirst($model), 'ContentModel', $config);
        }

        return $model;
    }

    /**
     * Function to get the download key from the update_sites
     *
     * @param   DatabaseDriver  $db           The Database Driver
     * @param   integer         $extensionId  The extension to get the download key for
     *
     * @since  1.3.0 (20220909)
     * @return string|boolean
     */
    public static function getDownloadId(DatabaseDriver $db, $extensionId)
    {
        $query = $db->getQuery(true);
        $query->select('extra_query')
            ->from($db->quoteName('#__update_sites', 'us'))
            ->join('LEFT', $db->quoteName('#__update_sites_extensions', 'use') . ' ON (' . $db->quoteName('use.update_site_id') . ' = ' . $db->quoteName('us.update_site_id') . ')')
            ->where($db->quoteName('use.extension_id') . ' = :extension_id')
            ->bind(':extension_id', $extensionId, ParameterType::INTEGER);

        $db->setQuery($query);

        $rawKey = $db->loadResult();

        if ($rawKey) {
            $key = \str_replace('key=', '', $rawKey);
        } else {
            $key = '';
        }

        return $key;
    }
}
