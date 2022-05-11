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

    public const WEBHOOK_URL = "https://copyleaks.herokuapp.com/webhook";
    public const RESULT_DOWNLOAD_URL = "https://copyleaks.herokuapp.com/download/";
    public const COMPLETION_WEBHOOK_URL = "https://copyleaks.herokuapp.com/export/export-id/completed";
    private const CRAWLED_WEBHOOK_URL = "https://copyleaks.herokuapp.com/export/export-id/crawled-version";
    public const PDF_WEBHOOK_URL = "https://copyleaks.herokuapp.com/export/export-id/pdf-report";

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

    /**
     * Exponential Backoff algorithm based on https://api.copyleaks.com/documentation/v3/exponential-backoff
     */
    public function retry(callable $method, $params = array())
    {
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

                $this->retry($method);
            }

            // Max retries exceeded
            throw $th;
        }
    }
    public function scan_completed_webhook($data)
    {
        // $data make sone decisions with this
        // error_log("Data passed to our webhook " . print_r($data, true));
        $this->url =  $_SERVER['REQUEST_URI'];
        $url = explode("/", $this->url);
		$status = $url[2];

        if ($status && $status !== "completed") {
            return;
        }

		error_log("URL breakdown for scan completed is " . print_r($url, true));
		error_log("Data passed to scan completed webhook is " . print_r($data, true));

        $plagiarismBreakdown = $data['results']['score'];

        // this should allow us export a result more than once while testing
        $this->exportId = $data['scannedDocument']['scanId'] . rand(0, 9);

        error_log('webhook receievd for scan id: ' . $this->exportId);

        $completion_url = str_replace("export-id", $this->exportId, self::COMPLETION_WEBHOOK_URL);
        $download_url = str_replace("export-id", $this->exportId, self::RESULT_DOWNLOAD_URL);
        $pdf_url = str_replace("export-id", $this->exportId, self::PDF_WEBHOOK_URL);

        /** TODO: Maybe get first few internet results available? */

        $model = new CopyleaksExportModel(
            $completion_url,
            array(new ExportResults('id', $download_url . 'export-result', "POST")),
            new ExportCrawledVersion($download_url . "export-webhook/crawled-version", "POST"),
            new ExportPdfReport($pdf_url, "POST")
        );

        error_log('webhook receieved....exporting....');

        $this->copyleaks->export($this->authToken, $data['scannedDocument']['scanId'], $this->exportId, $model);

        return header("HTTP/1.1 200 OK");
    }

    /**
     * This purely tells us the endpoints we defined and 
     * if Copyleaks was able to access them
     */
    public function export_completed_webhook($data)
    {
        error_log('Data passed when export completed ' . print_r($data, true));
    }

    public function download_pdf_webhook($data)
    {
        error_log('Data passed when pdf download called ' . print_r($data, true));
    }

    public function download($data)
    {
        error_log('Data passed when completed download webhook is called ' . print_r($data, true));
    }
}
