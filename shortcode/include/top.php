<!DOCTYPE html>
<?php
$_FILE = basename($_SERVER['SCRIPT_FILENAME'], '.php');
$_PART = explode('_', $_FILE);
?>
<?php
if (!empty($_PART[0])) $class []= sprintf('cfp-page:%s', $_PART[0]);
if (!empty($_PART[1])) $class []= sprintf('cfp-view:%s', $_PART[1]);
$class []= 'cfp-browser:load';
?>
<html class="<?= implode(' ', $class); ?>" lang="en">
<head>
    <!-- encoding -->
    <meta charset="utf-8">
    <!-- viewport -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <!-- title -->
    <title>Devoxx</title>
    <!-- favicon -->
    <link rel="shortcut icon" href="./src/gfx/favicon.png" type="image/png">
    <!-- style : site -->
    <link href="./src/css/site.css" rel="stylesheet">
    <!-- plugin : jquery -->
    <script src="./src/plugin/jquery/jquery.js"></script>
    <!-- script : site -->
    <script src="./src/script/site.js"></script>
</head>
