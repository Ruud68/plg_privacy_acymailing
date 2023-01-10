<?php

/**
 * [___HEADER_PHP___]
 */

namespace ___NAMESPACE___\Extension;

// No direct access
\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;
use Joomla\Component\Privacy\Administrator\Export\Domain;
use Joomla\Component\Privacy\Administrator\Plugin\PrivacyPlugin;
use Joomla\Component\Privacy\Administrator\Removal\Status;
use Joomla\Component\Privacy\Administrator\Table\RequestTable;
use Joomla\Database\ParameterType;
use ___NAMESPACE_HELPER___\AcymailingHelper;

/**
 * Plug-in to prevent Registering with Disposable Email Addresses
 *
 * @since  0.0.0
 */
class Acymailing extends PrivacyPlugin
{
    /**
     * Application object
     *
     * @var    CMSApplicationInterface
     * @since  1.0.0
     */
    protected $app;

    /**
     * Performs validation to determine if the data associated with a remove information request can be processed
     *
     * This event will not allow a super user account to be removed
     *
     * @param   RequestTable  $request  The request record being processed
     * @param   User          $user     The user account associated with this request if available
     *
     * @return  Status
     *
     * @since   1.0.0
     */
    public function onPrivacyCanRemoveData(RequestTable $request, User $user = null)
    {
        $status = new Status();

        if (!$user) {
            return $status;
        }

        if ($user->authorise('core.admin')) {
            $status->canRemove = false;
            $status->reason    = Text::_('PLG_PRIVACY_USER_ERROR_CANNOT_REMOVE_SUPER_USER');
        }

        return $status;
    }

    /**
     * Processes an export request for Acymailing user data
     *
     * This event will collect data for the following Acymailing tables:
     *
     * - #__acym_user
     * - #__acym_user_stat
     * - #__acym_url_click > #__acym_url | #__acym_mail
     *
     * @param   RequestTable  $request  The request record being processed
     * @param   User          $user     The user account associated with this request if available
     *
     * @return  \Joomla\Component\Privacy\Administrator\Export\Domain[]
     *
     * @since   1.0.0
     */
    public function onPrivacyExportRequest(RequestTable $request, User $user = null)
    {
        if (!$user) {
            return [];
        }

        // Get Acymailing User ID
        $userId = AcymailingHelper::getAcymailingUserId($user->id);

        if (!$userId) {
            // Acymailing user not found
            return [];
        }

        $domains = [];
        $domains[] = $this->createAcymailingUserDomain($userId);
        $domains[] = $this->createAcymailingUserStatsDomain($userId);
        $domains[] = $this->createAcymailingUrlClickDomain($userId);

        return $domains;
    }

    /**
     * Removes the data associated with a remove information request
     *
     * This event will pseudoanonymise the user data
     *
     * @param   RequestTable  $request  The request record being processed
     * @param   User          $user     The user account associated with this request if available
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onPrivacyRemoveData(RequestTable $request, User $user = null)
    {
        // Removal is done when removing Joomla User
        return;
    }

    /**
     * Adds the Acymailing Privacy Information to Joomla Privacy plugin.
     *
     * @return  array
     *
     * @since   1.0.0
     */
    public function onPrivacyCollectAdminCapabilities(): array
    {
        return [
            'Acymailing Privacy' => [
                Text::_('PLG_PRIVACY_ACYMAILING_CAPABILITY_USER'),
                Text::_('PLG_PRIVACY_ACYMAILING_CAPABILITY_USERSTATISTICS'),
                Text::_('PLG_PRIVACY_ACYMAILING_CAPABILITY_USERCLICKSTATISTICS'),
            ],
        ];
    }

    /**
     * Create the domain for the user data
     *
     * @param   int  $userid  The User ID
     *
     * @return  Domain
     *
     * @since   1.0.0
     */
    private function createAcymailingUserDomain(int $userid)
    {
        $domain   = $this->createDomain('acymailing_user', 'acymailing_user_data');
        $redacted = ['key'];
        $excluded = [];
        $item     = AcymailingHelper::getUserItems('#__acym_user', 'id', $userid, true);
        $data     = AcymailingHelper::processUserData($item, $excluded, $redacted);

        $domain->addItem($this->createItemFromArray($data, $userid));

        return $domain;
    }

    /**
     * Create the domain for the user statistics data
     *
     * @param   int  $userid  The User ID
     *
     * @return  Domain
     *
     * @since   1.0.0
     */
    private function createAcymailingUserStatsDomain(int $userid)
    {
        $domain   = $this->createDomain('acymailing_user_statistics', 'acymailing_user_statistics_data');
        $db       = $this->db;
        $redacted = [];
        $excluded = ['mail_id'];

        $query = $db->getQuery(true)
            ->select(['us.*', 'm.name as mailname'])
            ->from($db->quoteName('#__acym_user_stat', 'us'))
            ->join('LEFT', $db->quoteName('#__acym_mail', 'm') . ' ON ' . $db->quoteName('us.mail_id') . ' = ' . $db->quoteName('m.id'))
            ->where($db->quoteName('us.user_id') . ' = :userid')
            ->bind(':userid', $userid, ParameterType::INTEGER);

        $items = $db->setQuery($query)->loadAssocList();

        foreach ($items as $item) {
            $data = AcymailingHelper::processUserData($item, $excluded, $redacted);

            $domain->addItem($this->createItemFromArray($data, $item['mail_id']));
        }

        return $domain;
    }

    /**
     * Create the domain for the user messages data
     *
     * @param   int  $userid  The User ID
     *
     * @return  Domain
     *
     * @since   1.0.0
     */
    private function createAcymailingUrlClickDomain(int $userid)
    {
        $domain   = $this->createDomain('acymailing_user_urlclicks', 'acymailing_user_urlclicks_data');
        $db       = $this->db;
        $redacted = [];
        $excluded = ['mail_id', 'url_id'];

        $query = $db->getQuery(true)
            ->select(['uc.*', 'u.name as urlname', 'm.name as mailname'])
            ->from($db->quoteName('#__acym_url_click', 'uc'))
            ->join('LEFT', $db->quoteName('#__acym_url', 'u') . ' ON ' . $db->quoteName('uc.url_id') . ' = ' . $db->quoteName('u.id'))
            ->join('LEFT', $db->quoteName('#__acym_mail', 'm') . ' ON ' . $db->quoteName('uc.mail_id') . ' = ' . $db->quoteName('m.id'))
            ->where($db->quoteName('uc.user_id') . ' = :userid')
            ->bind(':userid', $userid, ParameterType::INTEGER);

        $items = $db->setQuery($query)->loadAssocList();

        foreach ($items as $item) {
            $data = AcymailingHelper::processUserData($item, $excluded, $redacted);

            $domain->addItem($this->createItemFromArray($data, $item['url_id']));
        }

        return $domain;
    }
}
