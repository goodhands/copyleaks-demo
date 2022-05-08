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
use Copyleaks\CopyleaksExportModel;
use Copyleaks\ExportCrawledVersion;
use Copyleaks\ExportResults;
use Copyleaks\ExportPdfReport;

// $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__);

// if (file_exists(".env")) {
//     $dotenv->load();
// }
class PlagiarismChecker
{
    public Copyleaks $copyleaks;
    public CopyleaksAuthToken $authToken;
    private string $scanId;

    private const WEBHOOK_URL = "https://copyleaks.herokuapp.com/webhook";
    private const RESULT_DOWNLOAD_URL = "https://copyleaks.herokuapp.com/download/";
    private const RESULT_DOWNLOAD_URL_LOCAL = "http://copyleaks.test/download/";
    private const WEBHOOK_URL_LOCAL = "http://copyleaks.test/webhook/";
    private const COMPLETION_WEBHOOK_URL = "http://copyleaks.herokuapp.com/export/export-id/completed";
    private const CRAWLED_WEBHOOK_URL = "http://copyleaks.herokuapp.com/export/export-id/crawled-version";
    private const PDF_WEBHOOK_URL = "http://copyleaks.herokuapp.com/export/export-id/pdf-report";

    public function __construct()
    {
        error_log('API_EMAIL_' . $_ENV['API_EMAIL']);

        $this->copyleaks = new Copyleaks();
        $this->authToken = $this->copyleaks->login($_ENV['API_EMAIL'], $_ENV['API_KEY']);

        error_log("Auth token " . print_r($this->authToken, true));

        $this->exportId = null;
        $this->scanId = "scanid1234";
    }

    public function submit($filename, $url = false)
    {
        error_log("submit called");

        $file = base64_encode(file_get_contents('C:/Users/HP/Documents/School/Year 3/Psychotherapy/180904061 Assignment.pdf'));
        // error_log(substr(chunk_split($file), 0, 100));
        $submission = new CopyleaksFileSubmissionModel(
            "aGVsbG8gd29ybGQ=",
            "assignment.pdf",
            new SubmissionProperties(
                new SubmissionWebhooks(self::WEBHOOK_URL . "/{STATUS}/" . $this->scanId),
                true, //include html (for PDFs yes)
                null, // developer payload: none for now
                false, // sandbox
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

        //time() should be replaced with scan id we will use to retrieve the result
        $this->copyleaks->submitFile('businesses', $this->authToken, $this->scanId, $submission);

        echo "Submitted successfully!";
    }

    public function webhook(CopyleaksAuthToken $authToken, $data)
    {
        error_log("Webhook called");
        // error_log("Data passed to our webhook " . print_r($data, true));

        $this->exportId = $data['scannedDocument']['scanId'] . rand(0, 10); // this should allow us export a result more than once

        str_replace("export-id", $this->exportId, self::COMPLETION_WEBHOOK_URL);
        str_replace("export-id", $this->exportId, self::RESULT_DOWNLOAD_URL);
        str_replace("export-id", $this->exportId, self::PDF_WEBHOOK_URL);

        $model = new CopyleaksExportModel(
            self::COMPLETION_WEBHOOK_URL,
            array(new ExportResults($data['scannedDocument']['scanId'], self::RESULT_DOWNLOAD_URL, "POST")),
            new ExportCrawledVersion(self::RESULT_DOWNLOAD_URL . "export-webhook/crawled-version", "POST"),
            new ExportPdfReport(self::PDF_WEBHOOK_URL, "POST")
        );

        error_log('webhook receieved....exporting....');

        $this->copyleaks->export($authToken, $this->exportId, $data['scannedDocument']['scanId'], $model);

        $request = $_SERVER['REQUEST_URI'];

        echo json_encode(array(
            'm' => 'o',
            'model' => $model,
            'request' => $request
        ));

        // Send this to ensure we stop getting pinged!
        return header("HTTP/1.1 200 OK");
    }

    public function download()
    {
        error_log("download called");

        $data = json_decode(file_get_contents('php://input'), true);

        error_log('data sent to download endpoint' . print_r($data, true));

        $request = $_SERVER['REQUEST_URI'];
        error_log("download called with " . print_r($request, true));

        echo json_encode(array(
            'request' => $request
        ));
    }
}