<?php

/**
 * Acymailing - Privacy plugin
 *
 * @version     ___VERSION___
 * @package     Joomla
 * @subpackage  plg_privacy_acymailing
 *
 * @author      Ruud van Lent <info@onlinecommunityhub.nl>
 * @copyright   Copyright (C) 2022 - ___CURRENTYEAR___ OnlineCommunityHub (J.L.R. van Lent Holding B.V.). All rights reserved.
 * @license     GNU/GPL version 3 or later
 * @link        https://onlinecommunityhub.nl
 */

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use ___NAMESPACE___\Extension\Acymailing;

return new class implements ServiceProviderInterface
{
    /**
     * Registers the service provider with a DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function register(Container $container)
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $subject = $container->get(DispatcherInterface::class);
                $config  = (array) PluginHelper::getPlugin('privacy', 'acymailing');
                $plugin = new Acymailing(
                    $subject,
                    $config
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
