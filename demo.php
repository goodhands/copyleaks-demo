<?php

include_once 'vendor/autoload.php';

use Copyleaks\CommandException;
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
use Copyleaks\RateLimitException;
use Copyleaks\UnderMaintenanceException;

// $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__);

// if (file_exists(".env")) {
//     $dotenv->load();
// }
class PlagiarismChecker
{
    public Copyleaks $copyleaks;
    public CopyleaksAuthToken $authToken;
    private string $scanId;
    private int $tries;

    private const WEBHOOK_URL = "https://copyleaks.herokuapp.com/webhook";
    private const RESULT_DOWNLOAD_URL = "https://copyleaks.herokuapp.com/download/";
    private const RESULT_DOWNLOAD_URL_LOCAL = "https://copyleaks.test/download/";
    private const WEBHOOK_URL_LOCAL = "https://copyleaks.test/webhook/";
    private const COMPLETION_WEBHOOK_URL = "https://copyleaks.herokuapp.com/export/export-id/completed";
    private const CRAWLED_WEBHOOK_URL = "https://copyleaks.herokuapp.com/export/export-id/crawled-version";
    private const PDF_WEBHOOK_URL = "https://copyleaks.herokuapp.com/export/export-id/pdf-report";

    private const MAX_RETRIES = 64;

    public function __construct()
    {
        $this->copyleaks = new Copyleaks();

        /** TODO: Store token for 48hrs before requesting new one */
        $this->authToken = $this->copyleaks->login($_ENV['API_EMAIL'], $_ENV['API_KEY']);

        error_log("Auth token " . print_r($this->authToken, true));

        $this->exportId = null;
        $this->scanId = "scanid-" . time();

        $this->retry_in = rand(1, 10);
        $this->tries = 1;
        $this->backOffExceptions = array(
            RateLimitException::class, CommandException::class,
            UnderMaintenanceException::class
        );
    }

    public function submit($filepath)
    {
        error_log("submit called");

        // $file = base64_encode(file_get_contents($filepath));

        $url = './file.txt';
        $fh = fopen($url, 'r');
        $bytes = filesize($url);

        $file = fread($fh, $bytes);

        $submission = new CopyleaksFileSubmissionModel(
            $file,
            "assignment.pdf",
            new SubmissionProperties(
                new SubmissionWebhooks(self::WEBHOOK_URL . "/{STATUS}/" . $this->scanId),
                false, //include html (for PDFs yes)
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

        $this->copyleaks->submitFile('businesses', $this->authToken, $this->scanId, $submission);

        echo "Submitted successfully!";
    }

    public function retry(callable $method, $params = array())
    {
        error_log('Called retry method on ' . print_r($method, true) . ' with params ' . print_r($params, true));

        try {
            $this->tries *= 2;
            return call_user_func($method, $params);
        } catch (Exception $th) {
            // If this is not the exception we expect then just quit.
            if (!in_array(get_class($th), $this->backOffExceptions)) {

                error_log('Not the exception we expect....' . get_class($th));

                throw $th;
            }

            // These exceptions are the ones we are expecting so we
            // will record our tries and only keep trying up till
            // MAX_RETRIES before quitting
            if ($this->tries <= self::MAX_RETRIES) {
                $next_try =  $this->tries + $this->retry_in;

                sleep($next_try);

                error_log('Slept for ' . $next_try . 'seconds, trying again...');

                $this->retry($method);
            }

            // Max retries exceeded
            throw $th;
        }
    }
    public function webhook($data)
    {
        error_log("Webhook called");
        // $data make sone decisions with this
        // error_log("Data passed to our webhook " . print_r($data, true));

        // this should allow us export a result more than once while testing
        $this->exportId = $data['scannedDocument']['scanId'] . rand(0, 9);

        error_log('webhook receievd for scan id: ' . $this->exportId);

        $completion_url = str_replace("export-id", $this->exportId, self::COMPLETION_WEBHOOK_URL);
        $download_url = str_replace("export-id", $this->exportId, self::RESULT_DOWNLOAD_URL);
        $pdf_url = str_replace("export-id", $this->exportId, self::PDF_WEBHOOK_URL);

        $model = new CopyleaksExportModel(
            $completion_url,
            array(new ExportResults($data['results']['internet'], $download_url . 'export-result', "POST")),
            new ExportCrawledVersion($download_url . "export-webhook/crawled-version", "POST"),
            new ExportPdfReport($pdf_url, "POST")
        );

        error_log('webhook receieved....exporting....');

        $this->copyleaks->export($this->authToken, $data['scannedDocument']['scanId'], $this->exportId, $model);

        return header("HTTP/1.1 200 OK");
    }

    public function download()
    {
        $request = $_SERVER['REQUEST_URI'];

        error_log("download called with " . print_r($request, true));

        $data = json_decode(file_get_contents('php://input'), true);
        // error_log('data sent to download endpoint' . print_r($data, true));

        if (strpos($request, 'pdf-report') !== false) {
            $headers = get_headers($_SERVER['REQUEST_URI']);
            error_log('data sent to pdf endpoint' . print_r(array('post' => $_POST, 'files' => $_FILES, 'headers' => $headers), true));

            error_log("download called");

            error_log('Completed??? ' . (int) isset($data['completed']));

            error_log("download called with " . print_r($request, true));

            echo json_encode(array(
                'request' => $request
            ));
        }
    }
}
