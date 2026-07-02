<?php

$ruleset = new \TwigCsFixer\Ruleset\Ruleset();
$ruleset->addStandard(new \TwigCsFixer\Standard\Twig());

$config = new \TwigCsFixer\Config\Config();
$config->setRuleset($ruleset);

$finder = new \TwigCsFixer\File\Finder();
$finder->in(__DIR__ . '/templates');
$config->setFinder($finder);

return $config;
