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

use Joomla\CMS\Cache\CacheController;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Version;
use Joomla\Component\Privacy\Administrator\Export\Domain;
use Joomla\Component\Privacy\Administrator\Export\Field;
use Joomla\Component\Privacy\Administrator\Export\Item;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Joomla\Event\Event;
use Joomla\Http\HttpFactory;

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
     * @return  string
     * @since   1.5.0 (20230518)
     */
    public static function truncate($str, $len = 0, $strip = \false, $ellipsis = '...'): string
    {
        // 20200121: Using MB_ functions to handle multi-byte language characters
        $result = $str;

        if ($strip) {
            // {tag}text{/tag} or {tag action}text{/tag}
            $result = \preg_replace('#{(.*?)}(.*?){\/(.*?)}#s', '', $result);

            // {tag} or {tag action}
            $result = \preg_replace('#{(.*?)}#s', '', $result);

            // <script type="....>...</script>
            $result = \preg_replace('#<script\b[^>]*>(.*?)<\/script>#is', '', $result);

            // [widgetkit: xyz]
            $result = \preg_replace('#\[(.*?)\]#s', '', $result);

            $result = \strip_tags($result);
            $result = \preg_replace('#\r|\n|\t|&nbsp;#', ' ', $result);
            $result = \preg_replace('#(  )#', ' ', $result);
            $result = \trim($result);
        }

        if (extension_loaded('mbstring')) {
            if (\mb_strlen($result) > $len && $len !== 0) {
                if ($len > \mb_strlen($ellipsis)) {
                    $len = $len - \mb_strlen($ellipsis);
                }

                $result = \trim(\mb_substr($result, 0, $len)) . $ellipsis;
            }
        } else {
            if (\strlen($result) > $len && $len !== 0) {
                if ($len > \strlen($ellipsis)) {
                    $len = $len - \strlen($ellipsis);
                }

                $result = \trim(\substr($result, 0, $len)) . $ellipsis;
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
     * @return  mixed  array when display message is \false, boolean when display message is \true
     * @since   0.0.0
     */
    public static function validateDownloadId(
        $url,
        $element,
        $downloadId,
        $writeCache = \true,
        $readCache = \true,
        $message = \false,
        $languagePrefix = ''
    ): mixed {
        /** @var \Joomla\CMS\Application\SiteApplication|\Joomla\CMS\Application\AdministratorApplication $app */
        $app = Factory::getApplication();

        $validationUri = clone Uri::getInstance($url);

        $cache = self::getCache('onlinecommunityhub_downloadids', 'output', ['caching' => \true, 'lifetime' => 24 * 60]);

        $response   = \false;
        $hash       = \md5($element);
        $downloadId = \trim((string) $downloadId);

        if ($readCache === \true) {
            // Get response from cache
            $response = $cache->get($hash);
        }

        if (!$response) {
            try {
                $headers = ['token' => \md5($downloadId),];
                $validationUri->setVar('task', 'updater.validateKey');
                $validationUri->setvar('downloadId', $downloadId);
                $validationUri->delvar('dummy');

                $validateUrl = $validationUri->toString();

                $response = (new HttpFactory())->getHttp()->get($validateUrl, $headers, 15);

                $store       = new \stdClass();
                $store->code = $response->getStatusCode();
                $store->body = (string) $response->getBody();
                $response    = $store;

                if ($writeCache) {
                    // Write response to cache
                    $cache->store($response, $hash, 'onlinecommunityhub_downloadids');
                }
            } catch (\Exception $e) {
                $app->enqueueMessage($e->getMessage(), 'error');

                $response = \false;
            }
        }

        if (!$message) {
            // Do not queue messages, just return response
            $result             = [];
            $result['code']     = $response->code;
            $result['status']   = 0;
            $result['valid_to'] = '';

            if (200 == $response->code) {
                $result = \json_decode($response->body, \true);
                $tzOffset = new \DateTimeZone($app->getConfig()->get('offset'));
                $valid_to = Factory::getDate(\strtotime($result['valid_to'] ?? 0));
                $valid_to->setTimeZone($tzOffset);
                $result['valid_to'] = \date(Text::_('DATE_FORMAT_LC3'), \strtotime((string) $valid_to));
            }

            return $result;
        }

        if ($response && 200 == $response->code) {
            $validationData = \json_decode($response->body, \true);

            if (\is_array($validationData) && \array_key_exists('status', $validationData) && \array_key_exists('valid_to', $validationData)) {
                switch ($validationData['status']) {
                    case 0:
                        // No Access or Invalid
                        $app->enqueueMessage(Text::_($languagePrefix . '_DOWNLOADID_INVALID_MSG'), 'error');

                        return \false;

                    case 1:
                        // Active
                        $tzOffset = new \DateTimeZone($app->getConfig()->get('offset'));
                        $valid_to = Factory::getDate(\strtotime($validationData['valid_to']));
                        $valid_to->setTimeZone($tzOffset);
                        $now = Factory::getDate('now', $tzOffset);

                        // Check if Valid To date is within 1 month
                        if (\strtotime((string) $now . '+ 1 month') > \strtotime((string) $valid_to)) {
                            $message = Text::sprintf(
                                $languagePrefix . '_DOWNLOADID_ACTIVE_MSG',
                                \date(Text::_('DATE_FORMAT_LC3'), \strtotime((string) $valid_to))
                            );
                            $app->enqueueMessage($message, 'warning');
                        }

                        return \true;
                }
            }
        }

        if ($response && 403 == $response->code) {
            $app->enqueueMessage(Text::_($languagePrefix . '_DOWNLOADID_INVALID_MSG'), 'error');

            return \false;
        }

        return \true;
    }

    /**
     * Function to prepare the plugin package update, to be called by onInstallerBeforePackageDownload
     *
     * @param   object   $package    The package information needed to download the update
     * @param   boolean  $extraData  Add Extra Data to request headers
     *
     * @return  object|bool
     * @since   0.0.0
     */
    public static function prepareUpdate($package, $extraData = \true): object|bool
    {
        $app = Factory::getApplication();

        $uri = clone Uri::getInstance($package->url);
        $host = $uri->getHost();

        if ($host !== 'onlinecommunityhub.nl' && $host !== 'och.developmenthub.nl') {
            // We will only handle our own extensions
            return \false;
        }

        $element = $uri->getVar('element', '');

        if (empty($element) || $element !== $package->plugin->name) {
            // We will only handle our own extension / element
            return \false;
        }

        // If no download key is set
        if (empty($package->downloadId)) {
            $app->enqueueMessage(Text::_($package->languagePrefix . '_DOWNLOADID_MISSING_MSG'), 'notice');

            return \false;
        }

        $package->downloadId = \trim($package->downloadId);
        $return              = self::validateDownloadId($package->url, $element, $package->downloadId, \false, \false, \true, $package->languagePrefix);

        if ($extraData) {
            $domain = Uri::getInstance()->getHost();

            if ($domain) {
                $package->headers['X-Requesting-Domain'] = $domain;
            }

            $version = new Version();

            if ($version) {
                $package->headers['X-Requesting-Joomlacms-Version'] = (string) $version->getShortVersion();
            }

            if (\phpversion()) {
                $package->headers['X-Requesting-Php-Version'] = (string) \phpversion();
            }

            $db = Factory::getContainer()->get('DatabaseDriver');

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
     * @return  void
     * @since   0.0.0
     */
    private static function setUpdateExtraQuery($pluginId, $downloadId): void
    {
        $downloadId = \trim($downloadId);
        $extraQuery = $downloadId == '' ? '' : 'key=' . $downloadId;

        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->createQuery()
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
     * Method to determine if we are on Joomla 5.x
     *
     * @param   string  $client  Determine if we are on administrator or site
     *
     * @return  boolean
     * @since   0.0.0
     */
    public static function isJoomla5($client = ''): bool
    {
        $version = new Version();

        $isJoomla5 = $version::MAJOR_VERSION === 5 ? \true : \false;

        if (empty($client)) {
            return $isJoomla5;
        }

        return ($isJoomla5 && Factory::getApplication()->isClient($client));
    }

    /**
     * Method to determine if we are on Joomla 5.x
     *
     * @param   string  $client  Determine if we are on administrator or site
     *
     * @return  boolean
     * @since   1.11.0 (20250611)
     */
    public static function isJoomla6($client = ''): bool
    {
        $version = new Version();

        $isJoomla6 = $version::MAJOR_VERSION === 6 ? \true : \false;

        if (empty($client)) {
            return $isJoomla6;
        }

        return ($isJoomla6 && Factory::getApplication()->isClient($client));
    }

    /**
     * Method to check if we are on a specified joomla version
     *
     * @param   string  $version  the version to check
     * @param   string  $compare  the comparison
     *
     * @return  boolean
     * @since   1.2.0 (20220906)
     */
    public static function isJoomlaVersion($version, $compare = '='): bool
    {
        $joomlaVersion = new Version();

        return version_compare($joomlaVersion->getShortVersion(), $version, $compare);
    }

    /**
     * Function to get a component model
     *
     * @param   string  $component  The component to get the model for
     * @param   string  $model      The model to get
     * @param   string  $location   The model location (site / admin)
     * @param   array   $config     The config setting to pass to the model instantiation
     *
     * @return  object
     * @since   1.1.0 (20220326)
     */
    public static function getModel($component = 'com_content', $model = 'article', $location = 'site', array $config = ['ignore_request' => \true]): object
    {
        return Factory::getApplication()->bootComponent($component)->getMVCFactory()->createModel(\ucfirst($model), \ucfirst($location), $config);
    }

    /**
     * Function to get a component table
     *
     * @param   string  $component  The component to get the table for
     * @param   string  $name       The table to get
     * @param   string  $prefix     The table prefix
     * @param   array   $config     The config setting to pass to the model instantiation
     *
     * @return  object
     * @since   1.9.0 (20240927)
     * @deprecated Get table via direct instantiating e.g. $tabel = new \Joomla\Component\Content\Administrator\Table\ArticleTable()
     */
    public static function getTable($component = 'com_content', $name = 'article', $prefix = 'administrator', array $config = []): object
    {
        return Factory::getApplication()->bootComponent($component)->getMVCFactory()->createTable(\ucfirst($name), \ucfirst($prefix), $config);
    }

    /**
     * Function to get a Cache Controler
     *
     * @param   string  $group    The cache group to get the cache controller for
     * @param   string  $type     The cache type, defaults to output
     * @param   array   $options  The cache options to pass, e.g. lifetime, caching
     *
     * @return  CacheController
     * @since   1.10.0 (20250526)
     */
    public static function getCache(string $group, string $type = 'output', array $options = []): CacheController
    {
        $options['defaultgroup'] = $group;

        return Factory::getContainer()->get(CacheControllerFactoryInterface::class)->createCacheController($type, $options);
    }

    /**
     * Function to get the download key from the update_sites
     *
     * @param   DatabaseDriver  $db           The Database Driver
     * @param   integer         $extensionId  The extension to get the download key for
     * @param   string          $packageName  The name for the package to get the download key for
     *
     * @return  string
     * @since   1.3.0 (20220909)
     */
    public static function getDownloadId(DatabaseDriver $db, $extensionId = 0, $packageName = ''): string
    {
        $query = $db->createQuery();
        $query->select('extra_query')
            ->from($db->quoteName('#__update_sites', 'us'))
            ->join('LEFT', $db->quoteName('#__update_sites_extensions', 'use') . ' ON (' . $db->quoteName('use.update_site_id') . ' = ' . $db->quoteName('us.update_site_id') . ')')
            ->join('LEFT', $db->quoteName('#__extensions', 'e') . ' ON (' . $db->quoteName('e.extension_id') . ' = ' . $db->quoteName('use.extension_id') . ')');

        if ($extensionId) {
            $query->where($db->quoteName('use.extension_id') . ' = :extension_id')
                ->bind(':extension_id', $extensionId, ParameterType::INTEGER);
        } elseif (!empty($packageName)) {
            $query->where($db->quoteName('e.element') . ' = :element')
                ->where($db->quoteName('e.type') . ' = ' . $db->quote('package'))
                ->bind(':element', $packageName, ParameterType::STRING);
        }

        $db->setQuery($query);

        $rawKey = $db->loadResult();

        if ($rawKey) {
            $key = \str_replace('key=', '', $rawKey);
        } else {
            $key = '';
        }

        return $key;
    }

    /**
     * Create a new com_privacy domain object
     * Source: Joomla\Component\Privacy\Administrator\Plugin\PrivacyPlugin [4.2.6]
     *
     * @param   string  $name         The domain's name
     * @param   string  $description  The domain's description
     *
     * @return  Domain
     * @since   1.4.0 (20230110)
     */
    public static function privacyCreateDomain($name, $description = ''): Domain
    {
        $domain              = new Domain();
        $domain->name        = $name;
        $domain->description = $description;

        return $domain;
    }

    /**
     * Create a com_privacy item object for an array
     * Source: Joomla\Component\Privacy\Administrator\Plugin\PrivacyPlugin [4.2.6]
     *
     * @param   array         $data    The array data to convert
     * @param   integer|null  $itemId  The ID of this item
     *
     * @return  Item
     * @since   1.4.0 (20230110)
     */
    public static function privacyCreateItemFromArray(array $data, $itemId = null): Item
    {
        $item = new Item();
        $item->id = $itemId;

        foreach ($data as $key => $value) {
            if (\is_object($value)) {
                $value = (array) $value;
            }

            if (\is_array($value)) {
                $value = print_r($value, \true);
            }

            $field        = new Field();
            $field->name  = $key;
            $field->value = $value;

            $item->addField($field);
        }

        return $item;
    }

    /**
     * Function to anomynize user data in database table
     *
     * @param   string  $table     The Database Table to get the data from
     * @param   string  $field     The Table Field to match the User ID with
     * @param   int     $userid    The User ID
     * @param   string  $setField  The Table Field to set the new value for
     * @param   string  $setValue  The new value to set
     *
     * @return  boolean
     * @since   1.4.0 (20230110)
     */
    public static function privacyAnomynizeUserData(string $table, string $field, int $userid, string $setField, string $setValue): bool
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->createQuery()
            ->update($db->quoteName($table))
            ->where($db->quoteName($field) . ' = :userid')
            ->set($db->quoteName($setField) . ' = ' . $db->quote($setValue))
            ->bind(':userid', $userid, ParameterType::INTEGER);

        $db->setQuery($query);

        return $db->execute();
    }

    /**
     * Function to delete user data in database table
     *
     * @param   string  $table     The Database Table to get the data from
     * @param   string  $field     The Table Field to match the User ID with
     * @param   int     $userid    The User ID
     *
     * @return  boolean
     * @since   1.4.0 (20230110)
     */
    public static function privacyDeleteUserData(string $table, string $field, int $userid): bool
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->createQuery()
            ->delete($db->quoteName($table))
            ->where($db->quoteName($field) . ' = :userid')
            ->bind(':userid', $userid, ParameterType::INTEGER);

        $db->setQuery($query);

        return $db->execute();
    }

    /**
     * Function to replace first occurence of the search string with the replacement string
     *
     * @param   string   $search   The string to search for
     * @param   string   $replace  The string to replace with
     * @param   string   $subject  The string to search in
     * @param   boolean  $first    Replace first (true) or last (false) occurence
     *
     * @return  string
     * @since   1.5.0 (20230217)
     */
    public static function strReplaceOne($search, $replace, $subject, $first = \true): string
    {
        if ($first) {
            $pos = \strpos($subject, $search);
        } else {
            $pos = \strrpos($subject, $search);
        }

        if ($pos !== \false) {
            $subject = substr_replace($subject, $replace, $pos, strlen($search));
        }

        return $subject;
    }

    /**
     * Adds a result value to an event
     *
     * @param   Event   $event  The event we were processing
     * @param   mixed   $value  The value to append to the event's results
     *
     * @return  void
     * @since   1.8.0
     */
    public static function returnFromEvent(Event $event, $value = null): void
    {
        $result = $event->getArgument('result') ?: [];

        if (!\is_array($result)) {
            $result = [$result];
        }

        $result[] = $value;

        $event->setArgument('result', $result);
    }

    /**
     * Function to list all methods in a class: used for debugging / development purposes
     * 
     * @param   object   $class           The class to get the information for
     * @param   boolean  $printBackTrace  Display a debug back trace
     * 
     * @return void
     */
    public static function debugClass($class, $printBackTrace = \true): void
    {
        $rClass    = new \ReflectionClass($class);
        $backTrace = \debug_backtrace();

        if ($printBackTrace) {
            dd($rClass, $backTrace);
        }

        dd($rClass);
    }
}
