<?php

include_once 'vendor/autoload.php';

use Copyleaks\Copyleaks;
use Copyleaks\SubmissionProperties;
use Copyleaks\SubmissionWebhooks;
use Copyleaks\CopyleaksFileSubmissionModel;
use Copyleaks\SubmissionActions;
use Copyleaks\SubmissionAuthor;
use Copyleaks\SubmissionFilter;
use Copyleaks\SubmissionScanning;
use Copyleaks\SubmissionScanningExclude;
use Copyleaks\SubmissionScanningCopyleaksDB;
use Copyleaks\SubmissionIndexing;
use Copyleaks\SubmissionRepository;
use Copyleaks\SubmissionExclude;
use Copyleaks\SubmissionPDF;
use Copyleaks\CopyleaksAuthToken;

$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

class PlagiarismChecker
{
    public Copyleaks $copyleaks;
    public CopyleaksAuthToken $authToken;

    private const WEBHOOK_URL = "http://pra.local";

    public function __construct()
    {
        error_log('API_EMAIL_' . $_ENV['API_EMAIL']);

        $this->copyleaks = new Copyleaks();
        $this->authToken = $this->copyleaks->login($_ENV['API_EMAIL'], $_ENV['API_KEY']);

        error_log("Auth token " . print_r($this->authToken, true));
    }

    public function submit($filename, $url = false)
    {
        $file = base64_encode(file_get_contents('C:/Users/HP/Documents/School/Year 3/Psychotherapy/180904061 Assignment.pdf'));
        error_log(substr($file, 0, 100));
        error_log(substr(chunk_split($file), 0, 100));
        $submission = new CopyleaksFileSubmissionModel(
            $file,
            "assignment.pdf",
            new SubmissionProperties(
                new SubmissionWebhooks(self::WEBHOOK_URL . "/{STATUS}"),
                true, //include html (for PDFs yes)
                null, // developer payload: none for now
                true, // sandbox
                6, // hours before copyleaks deletes the scan
                1, // a fast scan with highest sensibility (1)
                true,
                SubmissionActions::Scan,
                new SubmissionAuthor('user-1'), // the author id so we can learn their pattern
                new SubmissionFilter(true, true, true), // match exact words, paraphrased words, and similar words
                new SubmissionScanning(true, new SubmissionScanningExclude('user-1'), null, new SubmissionScanningCopyleaksDB(true, true)),
                new SubmissionIndexing((array)[new SubmissionRepository('pra-plag-checks')]),
                new SubmissionExclude(true, true, true, true, true),
                new SubmissionPDF(true, 'Report title', 'https://lti.copyleaks.com/images/copyleaks50x50.png', false)
            )
        );

        //time() should be replaced with scan id
        $this->copyleaks->submitFile('education', $this->authToken, time(), $submission);
    }

}

$p = new PlagiarismChecker();
$p->submit(true, true);
