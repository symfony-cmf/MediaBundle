<?php

/*
 * This file is part of the Symfony CMF package.
 *
 * (c) 2011-2015 Symfony CMF
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

$container->setParameter('cmf_testing.bundle_fqn', 'Symfony\Cmf\Bundle\MediaBundle');
$loader->import(CMF_TEST_CONFIG_DIR.'/default.php');
$loader->import(CMF_TEST_CONFIG_DIR.'/phpcr_odm.php');
if (defined('Symfony\Component\HttpKernel\Kernel::VERSION') && version_compare(\Symfony\Component\HttpKernel\Kernel::VERSION, '3.0', '>=')) {
    $loader->import(__DIR__.'/cmf_media_3_0.yml');
} else {
    $loader->import(__DIR__.'/cmf_media.yml');
}
$loader->import(__DIR__.'/security.yml');
