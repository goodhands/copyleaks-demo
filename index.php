<?php

include_once 'vendor/autoload.php';

use Goodhands\PlagiarismChecker;

$pg = new PlagiarismChecker();
$pg->submit('', true);
