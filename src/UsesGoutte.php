<?php

namespace Spekulatius\PHPScraper;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

trait UsesGoutte
{
    /**
     * Holds the client
     *
     * @var Goutte\Client
     */
    protected $client = null;

    /**
     * Holds the HttpClient
     *
     * @var Symfony\Contracts\HttpClient\HttpClientInterface;
     */
    protected $httpClient = null;

    /**
     * Holds the current page (a Crawler object)
     *
     * @var \Symfony\Component\DomCrawler\Crawler
     */
    protected $currentPage = null;

    /**
     * Was a temporary redirect involved in loading this request?
     *
     * @var bool
     */
    protected $usesTemporaryRedirect = false;

    /**
     * Should subsequent requests go to a different URL?
     *
     * @var string
     */
    protected $permanentRedirectUrl = '';

    /**
     * Which is the earliest moment to retry the request? (unix timestamp)
     *
     * @var int
     */
    protected $retryAt = 0;

    /**
     * Overwrites the client
     *
     * @param \Goutte\Client $client
     */
    public function setClient(GoutteClient $client): self
    {
        $this->client = $client;

        return $this;
    }

    /**
     * Overwrites the httpClient
     *
     * @param Symfony\Contracts\HttpClient\HttpClientInterface $httpClient
     */
    public function setHttpClient(HttpClientInterface $httpClient): self
    {
        $this->httpClient = $httpClient;

        return $this;
    }

    /**
     * Retrieve the client
     *
     * @param \Goutte\Client $client
     */
    public function client(): GoutteClient
    {
        return $this->client;
    }

    /**
     * Any URL-related methods are in `UsesUrls.php`.
     **/

    /**
     * Navigates to a new page using an URL.
     *
     * @param string $url
     */
    public function go(string $url): self
    {
        $this->client->initNewRequest();

        // Keep it around for internal processing.
        $this->currentPage = $this->client->request('GET', $url);

        // Remember request properties.
        $this->usesTemporaryRedirect = $this->client->usesTemporaryRedirect;
        $this->permanentRedirectUrl = $this->client->permanentRedirectUrl ?? '';
        $this->retryAt = $this->client->retryAt();
        if (!$this->retryAt && $this->statusCode() === 509 /* Bandwidth Limit Exceeded */) {
            $this->retryAt = strtotime('next month 12:00 UTC');
            // give providers in each timezone the chance to reset the traffic quota for month
        }
        return $this;
    }

    /**
     * Allows to set HTML content to process.
     *
     * This is intended to be used as a work-around, if you already have the DOM.
     *
     * @param string $url
     * @param string $content
     */
    public function setContent(string $url, string $content): self
    {
        // Overwrite the current page with a fresh Crawler instance of the content.
        $this->currentPage = new Crawler($content, $url);

        return $this;
    }

    /**
     * Fetch an asset from a given absolute or relative URL
     *
     * @param string $url
     */
    public function fetchAsset(string $url)
    {
        return $this
            ->httpClient
            ->request(
                'GET',
                ($this->currentPage === null) ? $url : $this->makeUrlAbsolute($url),
            )
            ->getContent();
    }

    /**
     * Click a link (either with title or url)
     *
     * @param string $titleOrUrl
     */
    public function clickLink($titleOrUrl): self
    {
        // If the string starts with http just go to it - we assume it's an URL
        if (\stripos($titleOrUrl, 'http') === 0) {
            // Go to a URL
            $this->go($titleOrUrl);
        } else {
            // Find link based on the title
            $link = $this->currentPage->selectLink($titleOrUrl)->link();

            // Click the link and store the DOMCrawler object
            $this->currentPage = $this->client->click($link);
        }

        return $this;
    }

    public function isTemporaryResult(): bool
    {
        return $this->usesTemporaryRedirect || \in_array($this->statusCode(), [
            408, // Request Timeout
            409, // Conflict
            419, // Page Expired
            420, // Enhance Your Calm
            421, // Misdirected Request
            423, // Locked
            425, // Too Early
            429, // Too Many Requests
            500, // Internal Server Error
            502, // Bad Gateway
            503, // Service Unavailable
            504, // Gateway Timeout
            507, // Insufficient Storage
            520, // Web Server returned an unknown error
            521, // Web Server is down
            522, // Connection Timed Out
            523, // Origin is unreachable
            524, // A timeout occurred
            525, // SSL Handshake Failed
            527, // Railgun Error
            529, // Site is overloaded
            598, // Network read timeout error
            599, // Network Connect Timeout Error
        ]);
    }

    public function isGone(): bool
    {
        return !$this->isTemporaryResult() && $this->statusCode() === 410 /* Gone */;
    }

    public function isPermanentError(): bool
    {
        return $this->statusCode() >= 400 && !$this->isTemporaryResult();
    }

    public function usesTemporaryRedirect(): bool
    {
        return $this->usesTemporaryRedirect;
    }

    public function permanentRedirectUrl(): string
    {
        return $this->permanentRedirectUrl;
    }

    public function retryAt(): int
    {
        return $this->retryAt;
    }

    public function statusCode(): int
    {
        if ($this->currentPage === null) {
            throw new \Exception('You can not access the status code before your first navigation using `go`.');
        }

        return $this->client->getResponse()->getStatusCode();
    }

    public function isSuccess(): bool
    {
        return $this->statusCode() >= 200 && $this->statusCode() <= 299;
    }

    public function isClientError(): bool
    {
        return $this->statusCode() >= 400 && $this->statusCode() <= 499;
    }

    public function isServerError(): bool
    {
        return $this->statusCode() >= 500 && $this->statusCode() <= 599;
    }

    public function isForbidden(): bool
    {
        return $this->statusCode() === 403;
    }

    public function isNotFound(): bool
    {
        return $this->statusCode() === 404;
    }
}
