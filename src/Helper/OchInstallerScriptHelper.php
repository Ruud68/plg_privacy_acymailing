<?php

/**
 * ochInstallerScriptHelper
 *
 * @version     ___VERSION_OCHINSTALLER___
 * @package     Joomla
 * @subpackage  com_installer
 *
 * @author      ___AUTHOR___
 * @copyright   ___COPYRIGHT_NS___
 * @license     ___LICENSE___
 * @link        ___LINK___
 */

namespace ___NAMESPACE_HELPER___;

// No direct access
\defined('_JEXEC') or die;

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Extension\ExtensionHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Mail\MailTemplate;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;
use Joomla\Registry\Registry;

class OchInstallerScriptHelper implements InstallerScriptInterface
{
    /**
     * Version of the currently installed extension
     * 
     * @var string
     */
    protected string $installedVersion;

    /**
     * Version of the extension that is being installed / upgraded to
     * 
     * @var string
     */
    protected string $newVersion;

    /**
     * The minimum Joomla! version to install this extension on
     * 
     * @var string
     */
    protected string $minimumJoomlaVersion = '5.3';

    /**
     * The maximum Joomla! version to install this extension on
     * 
     * @var string
     */
    protected string $maximumJoomlaVersion = '6.99';

    /**
     * The minimum PHP version to install this extension on
     * 
     * @var string
     */
    protected string $minimumPHPVersion = '8.1';

    /**
     * The extension with min / max version this extension depends on being installed
     * ['extensioname' => ['folder' => ..., 'clientId' => ..., 'type' => ... 'minVersion' => ... 'maxVersion' => ...]]
     * 
     * @var array
     * @since 2.4.0
     */
    protected array $dependsOnExtension = [];

    /**
     * The extension type (e.g. plugin)
     * 
     * @var string
     */
    protected string $type = '';

    /**
     * The extension name
     * 
     * @var string
     */
    protected string $element = '';

    /**
     * The extension (plugin) folder
     * 
     * @var string 
     */
    protected ?string $folder = \null;

    /**
     * Enable the plugin after installation (ignored on update)
     * 
     * @var boolean
     */
    protected bool $enablePlugin = \false;

    /**
     * @var array
     */
    protected array $preflightVariables;

    /**
     * @var array
     */
    protected array $postflightVariables;

    /**
     * @var array
     */
    protected array $installVariables;

    /**
     * @var array
     */
    protected array $updateVariables;

    /**
     * @var array
     */
    protected array $uninstallVariables;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->installedVersion = $this->getInstalledVersion();

        $variableMethods = [
            'component_warnings',
            'database_updates',
            'installation_messages',
            'remove_directories',
            'remove_files',
            'rename_files',
            'update_sites',
            'mail_templates'
        ];

        foreach ($variableMethods as $method) {
            $this->preflightVariables[$method]  = [];
            $this->postflightVariables[$method] = [];
            $this->installVariables[$method]    = [];
            $this->updateVariables[$method]     = [];
            $this->uninstallVariables[$method]  = [];
        }

        // Load all preflight maintenance variables
        $this->setPreFlightVariables();

        // Load all update maintenance variables
        $this->setPostflightVariables();

        // Load all update maintenance variables
        $this->setInstallVariables();

        // Load all update maintenance variables
        $this->setUpdateVariables();

        // Load all update maintenance variables
        $this->setUninstallVariables();
    }

    /**
     * Function called before extension installation/update/removal procedure commences.
     *
     * @param   string            $type     The type of change (install or discover_install, update, uninstall)
     * @param   InstallerAdapter  $adapter  The adapter calling this method
     *
     * @return  boolean  True on success
     * @since   0.0.0
     */
    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        $this->newVersion = $adapter->manifest->version;

        $msg = '';

        if (\in_array($type, ['install', 'update', 'discover_install'])) {
            // Check the minimum PHP version
            if ($this->minimumPHPVersion && !\version_compare(PHP_VERSION, $this->minimumPHPVersion, 'ge')) {
                $msg = 'You need PHP ' . $this->minimumPHPVersion . ' or later to ' . $type . ' ' . $adapter->getElement();
                Factory::getApplication()->enqueueMessage($msg, 'error');
            }

            // Check the minimum Joomla! version
            if ($this->minimumJoomlaVersion && !\version_compare(JVERSION, $this->minimumJoomlaVersion, 'ge')) {
                $msg = 'You need Joomla! ' . $this->minimumJoomlaVersion . ' or later to ' . $type . ' ' . $adapter->getElement();
                Factory::getApplication()->enqueueMessage($msg, 'error');
            }

            // Check the maximum Joomla! version
            if ($this->maximumJoomlaVersion && !\version_compare(JVERSION, $this->maximumJoomlaVersion, 'le')) {
                $msg = 'You need Joomla! ' . $this->maximumJoomlaVersion . ' or earlier to ' . $type . ' ' . $adapter->getElement();
                Factory::getApplication()->enqueueMessage($msg, 'error');
            }

            // Check the depending extensions
            if (!$this->checkDependencies()) {
                return \false;
            }

            if ($msg) {
                $installer = \method_exists($adapter, 'getParent') ? $adapter->getParent() : $adapter->parent;

                // Remove any messages / description to avoid confusion for user
                $installer->set('message', '');

                return \false;
            }
        }

        if (\strtolower($type) === 'update' && $this->installedVersion) {
            // Do preflight maintenance
            OchInstallerScriptHelper::doMaintenance($this->preflightVariables, $this->installedVersion);
        }

        return \true;
    }

    /**
     * Function called after extension installation/update/removal procedure commences.
     *
     * @param   string            $type     The type of change (install or discover_install, update, uninstall)
     * @param   InstallerAdapter  $adapter  The adapter calling this method
     *
     * @return  boolean  True on success
     * @since   0.0.0
     */
    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'uninstall') {
            // Do not run postflight routines on uninstall, use uninstall instead
            return \true;
        }

        OchInstallerScriptHelper::doMaintenance($this->postflightVariables, $this->installedVersion);

        if ($type === 'install' && $this->enablePlugin) {
            $this->publishPlugin();
        }

        return \true;
    }

    /**
     * Function called after the extension is installed.
     *
     * @param   InstallerAdapter  $adapter  The adapter calling this method
     *
     * @return  boolean  True on success
     * @since   0.0.0
     */
    public function install(InstallerAdapter $adapter): bool
    {
        OchInstallerScriptHelper::doMaintenance($this->installVariables, $this->installedVersion);

        return \true;
    }

    /**
     * Function called after the extension is updated.
     *
     * @param   InstallerAdapter  $adapter  The adapter calling this method
     *
     * @return  boolean  True on success
     * @since   0.0.0
     */
    public function update(InstallerAdapter $adapter): bool
    {
        OchInstallerScriptHelper::doMaintenance($this->updateVariables, $this->installedVersion);

        return \true;
    }

    /**
     * Function called after the extension is uninstalled.
     *
     * @param   InstallerAdapter  $adapter  The adapter calling this method
     *
     * @return  boolean  True on success
     * @since   0.0.0
     */
    public function uninstall(InstallerAdapter $adapter): bool
    {
        OchInstallerScriptHelper::doMaintenance($this->uninstallVariables, $this->installedVersion);

        return \true;
    }

    /**
     * Set the preflight maintenance variables
     *
     * @return  void
     * @since   0.0.0
     */
    protected function setPreFlightVariables(): void
    {
        // Left empty intentionally
    }

    /**
     * Set the postflight maintenance variables
     *
     * @return  void
     * @since   0.0.0
     */
    protected function setPostFlightVariables(): void
    {
        // Left empty intentionally
    }

    /**
     * Set the (postflight) maintenance variables
     *
     * @return void
     * @since   0.0.0
     */
    protected function setInstallVariables(): void
    {
        // Left empty intentionally
    }

    /**
     * Set the (postflight) maintenance variables
     *
     * @return void
     * @since   0.0.0
     */
    protected function setUpdateVariables(): void
    {
        // Left empty intentionally
    }

    /**
     * Set the (postflight) maintenance variables
     *
     * @return  void
     * @since   0.0.0
     */
    protected function setUninstallVariables(): void
    {
        // Left empty intentionally
    }

    /**
     * Get the version of the current installed plugin / module / component
     *
     * @return  string Installed version number or 0 when not installed
     * @since   1.0.0
     */
    private function getInstalledVersion(): string
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->createQuery();

        $query->select($db->quoteName('manifest_cache'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = :type')
            ->where($db->quoteName('element') . ' = :element')
            ->bind(':type', $this->type, ParameterType::STRING)
            ->bind(':element', $this->element, ParameterType::STRING);

        if (!is_null($this->folder)) {
            $query->where($db->quoteName('folder') . ' = :folder')
                ->bind(':folder', $this->folder, ParameterType::STRING);
        }

        $db->setQuery($query);

        $result = $db->loadResult();

        if ($result) {
            $return = \json_decode($result);

            if ($return) {
                return $return->version;
            } else {
                return '0';
            }
        } else {
            return '0';
        }
    }

    /**
     * Function to remove files
     *
     * @param   array    $varMaintenanceVariables  Array with maintenance variables
     * @param   string   $installedVersion         The current version
     *
     * @return  void
     * @since   1.0.0
     */
    public function doMaintenance($varMaintenanceVariables, $installedVersion): void
    {
        if (\array_key_exists('remove_files', $varMaintenanceVariables)) {
            $this->removeFiles($varMaintenanceVariables['remove_files'], $installedVersion);
        }

        if (\array_key_exists('remove_directories', $varMaintenanceVariables)) {
            $this->removeDirectories($varMaintenanceVariables['remove_directories'], $installedVersion);
        }

        if (\array_key_exists('installation_messages', $varMaintenanceVariables)) {
            $this->installationMessages($varMaintenanceVariables['installation_messages'], $installedVersion);
        }

        if (\array_key_exists('component_warnings', $varMaintenanceVariables)) {
            $this->componentWarnings($varMaintenanceVariables['component_warnings'], $installedVersion);
        }

        if (\array_key_exists('rename_files', $varMaintenanceVariables)) {
            $this->renameFiles($varMaintenanceVariables['rename_files'], $installedVersion);
        }

        if (\array_key_exists('database_updates', $varMaintenanceVariables)) {
            $this->updateDatabase($varMaintenanceVariables['database_updates'], $installedVersion);
        }

        if (\array_key_exists('update_sites', $varMaintenanceVariables)) {
            $this->removeUpdateSite($varMaintenanceVariables['update_sites'], $installedVersion);
        }

        if (\array_key_exists('mail_templates', $varMaintenanceVariables)) {
            $this->handleMailTemplates($varMaintenanceVariables['mail_templates'], $installedVersion);
        }
    }

    /**
     * Function to remove files
     *
     * @param   array   $varRemoveFiles    Files to remove
     * @param   string  $installedVersion  Version number of installed plugin, module, component
     *
     * @return  void
     * @since   1.0.0
     */
    private function removeFiles($varRemoveFiles, $installedVersion): void
    {
        if (!empty($varRemoveFiles)) {
            $application = Factory::getApplication();

            foreach ($varRemoveFiles as $removeFile) {
                if (\version_compare($installedVersion, $removeFile['version'], $removeFile['compare'])) {
                    if (\is_string($removeFile['file'])) {
                        $removeFile['file'] = (array) $removeFile['file'];
                    }

                    foreach ($removeFile['file'] as $file) {
                        if (\file_exists($file)) {
                            if (File::delete($file)) {
                                $application->enqueueMessage(
                                    'Obsolete (left-over from previous release) file "' . $file
                                        . '" successfully removed.',
                                    'Message'
                                );
                            } else {
                                $application->enqueueMessage(
                                    'File "' . $file
                                        . '" (left-over from previous release) could not be removed, please remove manually.',
                                    'Warning'
                                );
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Function to display installation messages
     *
     * @param   array   $varInstallationMessages    Installation Messages
     * @param   string  $installedVersion           Version number of installed plugin, module, component
     *
     * @return  void
     * @since   1.0.0
     */
    private function installationMessages($varInstallationMessages, $installedVersion): void
    {
        if (!empty($varInstallationMessages)) {
            $application = Factory::getApplication();

            foreach ($varInstallationMessages as $installationMessage) {
                if (\version_compare($installedVersion, $installationMessage['version'], $installationMessage['compare'])) {
                    $application->enqueueMessage($installationMessage['message'], $installationMessage['type']);
                }
            }
        }
    }

    /**
     * Function to display component warnings
     *
     * @param   array   $varComponentWarnings    Warnings
     * @param   string  $installedVersion        Version number of installed plugin, module, component
     *
     * @return  void
     * @since   1.0.0
     */
    private function componentWarnings($varComponentWarnings, $installedVersion): void
    {
        if (!empty($varComponentWarnings)) {
            $application = Factory::getApplication();

            foreach ($varComponentWarnings as $componentWarning) {
                if (\version_compare($installedVersion, $componentWarning['version'], $componentWarning['compare'])) {
                    if (\file_exists($componentWarning['component'])) {
                        $application->enqueueMessage($componentWarning['message'], $componentWarning['type']);
                    }
                }
            }
        }
    }

    /**
     * Function to remove directories
     *
     * @param   array   $varRemoveDirectories    Directories to remove
     * @param   string  $installedVersion        Version number of installed plugin, module, component
     *
     * @return  void
     * @since   1.0.0
     */
    private function removeDirectories($varRemoveDirectories, $installedVersion): void
    {
        if (!empty($varRemoveDirectories)) {
            $application = Factory::getApplication();

            foreach ($varRemoveDirectories as $removeDirectory) {
                if (\version_compare($installedVersion, $removeDirectory['version'], $removeDirectory['compare'])) {
                    if (\is_string($removeDirectory['folder'])) {
                        $removeDirectory['folder'] = (array) $removeDirectory['folder'];
                    }

                    foreach ($removeDirectory['folder'] as $folder) {
                        if (\is_dir($folder)) {
                            if (Folder::delete($folder)) {
                                $application->enqueueMessage(
                                    'Obsolete (left-over from previous release) directory "' . $folder
                                        . '" successfully removed.',
                                    'Message'
                                );
                            } else {
                                $application->enqueueMessage(
                                    'Directory "' . $folder
                                        . '" (left-over from previous release) could not be removed, please remove manually.',
                                    'Warning'
                                );
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Function to update database(s)
     *
     * @param   array    $varUpdateDatabase    SQL queries
     * @param   string   $installedVersion     Version number of installed plugin, module, component
     *
     * @return  boolean
     * @since   1.0.0
     */
    private function updateDatabase($varUpdateDatabase, $installedVersion): bool
    {
        if (!empty($varUpdateDatabase)) {
            $application = Factory::getApplication();
            $db          = Factory::getContainer()->get(DatabaseInterface::class);

            foreach ($varUpdateDatabase as $UpdateDatabase) {
                $error       = \false;
                $stopOnError = \true;

                if (\array_key_exists('stoponerror', $UpdateDatabase) && $UpdateDatabase['stoponerror'] === \false) {
                    $stopOnError = \false;
                }

                if (\version_compare($installedVersion, $UpdateDatabase['version'], $UpdateDatabase['compare'])) {
                    try {
                        $query = $UpdateDatabase['query'];
                        $db->setQuery($query);
                        $db->execute();
                    } catch (\Exception $e) {
                        $message = $e->getMessage() . ($stopOnError ? '' : ' | Not stopping as requested...');

                        $application->enqueueMessage($message, 'error');
                        $error = \true;

                        if ($stopOnError) {
                            return \false;
                        }
                    }

                    if (!$error) {
                        if (\array_key_exists('message', $UpdateDatabase)) {
                            $application->enqueueMessage($UpdateDatabase['message'], $UpdateDatabase['type']);
                        }
                    }
                }
            }
        }

        return \true;
    }

    /**
     * Function to display postflight messages after installation or update
     *
     * @param   array             $varPostflightMessages  Message
     * @param   string            $route                  The action being performed
     * @param   InstallerAdapter  $adapter                The class calling this method
     * @param   bool              $append                 append or replace message
     *
     * @return  void
     * @since   1.0.0
     */
    public function postflightMessages($varPostflightMessages, $route, $adapter, $append = \true): void
    {
        if (!empty($varPostflightMessages)) {
            $installer = \method_exists($adapter, 'getParent') ? $adapter->getParent() : $adapter->parent;
            $append ? $message = $installer->get('message') : $message = '';

            foreach ($varPostflightMessages as $postflightMessage) {
                $message .= $postflightMessage['message'];
            }

            $installer->set('message', $message);
        }
    }

    /**
     * Function to rename files
     *
     * @param   array   $varRenameFiles    Files to remove
     * @param   string  $installedVersion  Version number of installed plugin, module, component
     *
     * @return  void
     * @since   1.0.0
     */
    private function renameFiles($varRenameFiles, $installedVersion): void
    {
        if (!empty($varRenameFiles)) {
            $application = Factory::getApplication();

            foreach ($varRenameFiles as $renameFile) {
                if (\version_compare($installedVersion, $renameFile['version'], $renameFile['compare'])) {
                    if (\file_exists($renameFile['oldname'])) {
                        if (File::move($renameFile['oldname'], $renameFile['newname'])) {
                            $application->enqueueMessage(
                                'File "' . $renameFile['oldname']
                                    . '" successfully renamed to file "' . $renameFile['newname'] . '"',
                                'Message'
                            );
                        } else {
                            $application->enqueueMessage(
                                'File "' . $renameFile['file']
                                    . '" (left-over from previous release) could not be renamed, please rename manually to file "'
                                    . $renameFile['newname'] . '"',
                                'Warning'
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * Function to remove the URL for the Update Site table
     *
     * @param   array   $varRemoveLocations  Files to remove
     * @param   string  $installedVersion    Version number of installed plugin, module, component
     *
     * @return  void
     * @since   1.0.0
     */
    private function removeUpdateSite($varRemoveLocations, $installedVersion): void
    {
        if (!empty($varRemoveLocations)) {
            $application = Factory::getApplication();
            $db          = Factory::getContainer()->get(DatabaseInterface::class);

            foreach ($varRemoveLocations as $removeLocation) {
                if (\version_compare($installedVersion, $removeLocation['version'], $removeLocation['compare'])) {
                    // Remove obsolete update site location from database (if found)
                    $query = $db->createQuery();
                    $query
                        ->select($db->quoteName(array('update_site_id', 'location')))
                        ->from($db->quoteName('#__update_sites'))
                        ->where($db->quoteName('location') . ' = :location')
                        ->bind(':location', $removeLocation['updatesite'], ParameterType::STRING);

                    $db->setQuery($query);
                    $row = $db->loadRow();

                    if (!empty($row)) {
                        // Remove record from #__update_sites
                        $query = $db->createQuery();
                        $query
                            ->delete($db->quoteName('#__update_sites'))
                            ->where($db->quoteName('update_site_id') . ' = :id')
                            ->bind(':id', $row[0], ParameterType::INTEGER);

                        $db->setQuery($query);
                        $result_us = $db->execute();

                        // Remove record from #__update_sites_extensions
                        $query = $db->createQuery();
                        $query
                            ->delete($db->quoteName('#__update_sites_extensions'))
                            ->where($db->quoteName('update_site_id') . ' = :id')
                            ->bind(':id', $row[0], ParameterType::INTEGER);

                        $db->setQuery($query);
                        $result_use = $db->execute();

                        if ($result_us && $result_use) {
                            $application->enqueueMessage(
                                'Obsolete (left-over from previous release) Update Site "' . $removeLocation['updatesite']
                                    . '" successfully removed',
                                'Message'
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * Function to remove the URL for the Update Site table
     *
     * @param   array   $varMailTemplates  mail templates to install / update
     * @param   string  $installedVersion  Version number of installed plugin, module, component
     *
     * @return  void
     * @since   1.0.0
     */
    private function handleMailTemplates($varMailTemplates, $installedVersion): void
    {
        if (!empty($varMailTemplates)) {
            foreach ($varMailTemplates as $mailTemplate) {
                if (\in_array($mailTemplate['task'], ['create', 'update'])) {
                    /**
                     * $key
                     * $subject
                     * $body
                     * $tags
                     * $htmlbody
                     */
                    \extract($mailTemplate);
                    if (\version_compare($installedVersion, $mailTemplate['version'], $mailTemplate['compare'])) {
                        try {
                            MailTemplate::createTemplate($key, $subject, $body, $tags, $htmlbody);
                        } catch (\Exception $e) {
                            MailTemplate::updateTemplate($key, $subject, $body, $tags, $htmlbody);
                        }
                    }
                } elseif ($mailTemplate['task'] == 'delete') {
                    MailTemplate::deleteTemplate($mailTemplate['key']);
                }
            }
        }
    }

    /**
     * Function to enable a plugin on installation
     * 
     * @return  void
     * @since   1.0.0
     */
    public function publishPlugin(): void
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->createQuery()
            ->update('#__extensions')
            ->set($db->quoteName('enabled') . ' = 1')
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('element') . ' = :plugin')
            ->where($db->quoteName('folder') . ' = :folder')
            ->bind(':plugin', $this->element, ParameterType::STRING)
            ->bind(':folder', $this->folder, ParameterType::STRING);
        $db->setQuery($query);
        $db->execute();
    }

    /**
     * Function to get the params for the (intalled) extension
     * 
     * @return  array
     * @since   1.0.0
     */
    public function getParams(): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        // Build the query
        $query = $db->createQuery()
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = :type')
            ->where($db->quoteName('element') . ' = :element');

        if ($this->type == 'plugin') {
            $query->where($db->quoteName('folder') . ' = :folder')
                ->bind(':folder', $this->folder, ParameterType::STRING);
        }

        $query->bind(':type', $this->type, ParameterType::STRING)
            ->bind(':element', $this->element, ParameterType::STRING);

        $db->setQuery($query);

        // Load the single cell and json_decode data
        $result = $db->loadResult();

        if (\is_null($result)) {
            return [];
        }

        $result = \json_decode($result, \true);

        return $result === null ? [] : $result;
    }

    /**
     * Function to save the params for the (installed) extension
     * 
     * @param   array  $params  The params to store for the extension
     * 
     * @return  bool
     * @since   1.0.0
     */
    public function saveParams(array $params): bool
    {
        $paramsString = \json_encode($params);
        /** @var \Joomla\Database\DatabaseDriver  $db */
        $db           = Factory::getContainer()->get(DatabaseInterface::class);

        // Build the query
        $query = $db->createQuery()
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = :params')
            ->where($db->quoteName('type') . ' = :type')
            ->where($db->quoteName('element') . ' = :element');

        if ($this->type === 'plugin') {
            $query->where($db->quoteName('folder') . ' = :folder')
                ->bind(':folder', $this->folder, ParameterType::STRING);
        }

        $query->bind(':params', $paramsString, ParameterType::STRING)
            ->bind(':type', $this->type, ParameterType::STRING)
            ->bind(':element', $this->element, ParameterType::STRING);

        // Update table
        $result = $db->setQuery($query)->execute();

        return $result;
    }

    /**
     * Function to remove one or more configuration parameters
     * 
     * @param   array  $params  The params array to emove keys from
     * @param   mixed  $remove  The key (string) or keys (array) to remove from the params
     * 
     * @return  array
     * @since   1.0.0
     */
    public function removeParams(array $params, $remove): array
    {
        if (\is_string($remove)) {
            $remove = [$remove]; // Convert string to array with one element
        }

        foreach ($remove as $key) {
            if (\array_key_exists($key, $params)) {
                unset($params[$key]);
            }
        }

        return $params;
    }

    /**
     * Function to rename one or more configuration parameters
     * 
     * @param   array  $params  The params array to emove keys from
     * @param   array  $rename  The the rename array in format ['old' => 'new']
     * 
     * @return  array
     * @since   1.0.0
     */
    public function renameParams(array $params, array $rename): array
    {
        foreach ($rename as $old => $new) {
            if (\array_key_exists($old, $params)) {
                $params[$new] = $params[$old];
                unset($params[$old]);
            }
        }

        return $params;
    }

    /**
     * Function to set / add one or more configuration parameters
     * 
     * @param   array  $params  The params array to add or change values from
     * @param   array  $config  The key (string) or keys (array) to change or add
     * 
     * @return  array
     * @since   1.0.0
     */
    public function setParams(array $params, array $config): array
    {
        foreach ($config as $key => $value) {
            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * Function to store a JSON file with configuration parameters to disk (tmp directory)
     * 
     * @return  integer|false  number of characters stored or \false
     * @since   1.0.0
     */
    public function backupParams(): int|false
    {
        $params = $this->getParams();
        /** @var AdministratorApplication $app */
        $app  = Factory::getApplication();
        $path = $app->getConfig()->get('tmp_path');

        return \file_put_contents($path . '/' . $this->type . '-' . $this->element . '-params-' . \time() . '.json', \json_encode($params, JSON_PRETTY_PRINT));
    }

    /**
     * Function to check dependend installed extensions
     * 
     * @return  bool
     * @since   2.4.0
     */
    public function checkDependencies(): bool
    {
        if (empty($this->dependsOnExtension)) {
            // No dependencies to check
            return \true;
        }

        foreach ($this->dependsOnExtension as $element => $extension) {
            $extension['clientId']   = $extension['clientId'] ?? null;
            $extension['folder']     = $extension['folder'] ?? null;
            $extension['minVersion'] = isset($extension['minVersion']) ? $extension['minVersion'] : 0;
            $extension['maxVersion'] = isset($extension['maxVersion']) ? $extension['maxVersion'] : 99999;

            if ($dependExtension = ExtensionHelper::getExtensionRecord($element, $extension['type'], $extension['clientId'], $extension['folder'])) {
                $manifest = new Registry($dependExtension->manifest_cache);

                $installedVersion = $manifest->get('version', 0);

                if (\version_compare($installedVersion, $extension['minVersion'], '<')) {
                    $msg = 'You need Extension ' . $element . ' version ' . $extension['minVersion'] . ' or later for ' . $this->element . ', current installed version is: ' . $installedVersion;
                    Factory::getApplication()->enqueueMessage($msg, 'error');

                    return \false;
                }
                if (\version_compare($installedVersion, $extension['maxVersion'], '>')) {
                    $msg = 'You need Extension ' . $element . ' version ' . $extension['maxVersion'] . ' or earlier for ' . $this->element . ', current installed version is: ' . $installedVersion;
                    Factory::getApplication()->enqueueMessage($msg, 'error');

                    return \false;
                }
            } else {
                // Not installed
                $msg = $this->element . ' requires Extension ' . $element . '. This extension is not installed.';
                Factory::getApplication()->enqueueMessage($msg, 'error');

                return \false;
            }
        }

        return \true;
    }

    /**
     * Function to check if the namespace has altered adn reset opcache when it did to avoid installation error / warning
     * This function should be called in preflight
     * 
     * @param   string  $newClass  The namespaced class to check
     * 
     * @return  void
     * @since   2.5.0
     */
    public function checkNameSpace($newClass): void
    {

        if (
            \function_exists('opcache_reset') &&
            \class_exists($newClass, \false)
        ) {
            try {
                // class_exists is not case sensitive so we need to use reflection to get the class and then to get it's actual name
                $reflector      = new \ReflectionClass($newClass);
                $installedClass = $reflector->getName();
            } catch (\Throwable $e) {
                // Nothing to do as the class is not present on the server
                return;
            }

            if ($installedClass !== $newClass) {
                opcache_reset();
                Factory::getApplication()->enqueueMessage(
                    'opcache cleared to implement new namespace.',
                    'Notice'
                );
            }
        }
    }
}
