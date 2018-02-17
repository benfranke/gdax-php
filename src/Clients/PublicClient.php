<?php

namespace GDAX\Clients;

use GDAX\Interfaces\ResponseTypeInterface;
use GDAX\Types\Request\Market\Product as RequestProduct;
use GDAX\Types\Response\Market\Currency as ResponseCurrency;
use GDAX\Types\Response\Market\Product as ResponseProduct;
use GDAX\Types\Response\Market\Product24HourStats as ResponseProduct24HourStats;
use GDAX\Types\Response\Market\ProductHistoricRate as ResponseProductHistoricRate;
use GDAX\Types\Response\Market\ProductOrderBook as ResponseProductOrderBook;
use GDAX\Types\Response\Market\ProductTicker as ResponseProductTicker;
use GDAX\Types\Response\Market\Time as ResponseTime;
use GDAX\Types\Response\Market\Trade as ResponseTrade;
use GDAX\Types\Response\ResponseContainer;
use GDAX\Utilities\GDAXConstants;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;

/**
 * Class PublicClient
 *
 * @author Benjamin Franke
 */
class PublicClient {

    /**
     * @var string
     */
    protected $productId;

    /**
     * @var string
     */
    protected $baseURL = GDAXConstants::GDAX_API_URL;

    /**
     * @return string
     */
    public function getProductId() {
        return $this->productId;
    }

    /**
     * @param string $productId
     *
     * @return PublicClient
     */
    public function setProductId($productId) {
        $this->productId = $productId;
        return $this;
    }

    /**
     * @return string
     */
    public function getBaseURL() {
        return $this->baseURL;
    }

    /**
     * @param string $baseURL
     *
     * @return PublicClient
     */
    public function setBaseURL($baseURL) {
        $this->baseURL = $baseURL;
        return $this;
    }

    /**
     * @param array  $uriParts
     * @param string $type
     * @param array  $options
     *
     * @return ResponseTypeInterface
     */
    protected function get(array $uriParts, $type, array $options = []) {
        $response = $this->request(GDAXConstants::METHOD_GET, $uriParts, $options);
        if (!is_array($response)) {
            throw new \Exception('Received invalid response : ' . $response .' when expecting an array ');
        }
        return (new ResponseContainer)->setRawData($response)->mapResponseToType($type)->getData();
    }

    /**
     * @param string $method
     * @param array  $uriParts
     * @param array  $options
     * @param array  $headers
     *
     * @throws \Exception
     *
     * @return array
     */
    protected function request($method, array $uriParts, array $options = [], array $headers = []) {

        $retries = 0;

        $requestOptions = [
            'base_uri'    => $this->baseURL,
            RequestOptions::HTTP_ERRORS => false,
        ];

        if ($streamHandle = fopen('php://memory', 'r+') !== false) {
            $requestOptions[RequestOptions::SINK] = \GuzzleHttp\Psr7\stream_for($streamHandle);
        };

        switch ($method) {

            case GDAXConstants::METHOD_POST:
            case GDAXConstants::METHOD_PUT:
                $requestOptions[RequestOptions::JSON] = $options;
                break;

            case GDAXConstants::METHOD_GET:
            case GDAXConstants::METHOD_DELETE:
                $requestOptions[RequestOptions::QUERY] = $options;
                break;

            default:
                throw new \LogicException('Invalid method specified');

        }

        $client = new Client($requestOptions);

        $uri = implode('/', $uriParts);

        $requestOptions[RequestOptions::HEADERS] = GDAXConstants::$defaultHeaders + $headers;

        $response = new Response();

        while ($retries < 5) {

            $response = $client->request($method, $uri, $requestOptions);

            switch ($response->getStatusCode()) {

                case 429:
                    usleep(500000);
                    $retries++;
                    break;

                default:
                    break(2);

            }

        }

        $data = json_decode($response->getBody()->getContents(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception(
                'Received invalid JSON in response. Error: ' . json_last_error_msg() .
                ' - Body: ' . $response->getBody()->getContents()
            );
        }

        if (!empty($response->getHeaders()['CB-BEFORE'])) {
            $data['before'] = $response->getHeaders()['CB-BEFORE'];
        }

        if (!empty($response->getHeaders()['CB-AFTER'])) {
            $data['after'] = $response->getHeaders()['CB-AFTER'];
        }

        return $data;

    }

    /**
     * Get a list of available currency pairs for trading.
     *
     * @return string
     */
    public function getProducts() {
        return $this->get(['products'], ResponseProduct::class);
    }

    /**
     * Get a list of open orders for a product. The amount of detail shown can be customized with the level parameter.
     *
     * @param RequestProduct $product
     *
     * @return ResponseTypeInterface
     */
    public function getProductOrderBook(RequestProduct $product) {
        return $this->get(['products', $product->getProductId(), 'book'], ResponseProductOrderBook::class, $product->toArray());
    }

    /**
     * Snapshot information about the last trade (tick), best bid/ask and 24h volume.
     *
     * @param RequestProduct $product
     *
     * @return ResponseTypeInterface
     */
    public function getProductTicker(RequestProduct $product) {
        return $this->get(['products', $product->getProductId(), 'ticker'], ResponseProductTicker::class);
    }

    /**
     * List the latest trades for a product.
     *
     * @param RequestProduct $product
     *
     * @return ResponseTypeInterface
     */
    public function getTrades(RequestProduct $product) {
        return $this->get(['products', $product->getProductId(), 'trades'], ResponseTrade::class, $product->toArray());
    }

    /**
     * Historic rates for a product. Rates are returned in grouped buckets based on requested granularity.
     *
     * @param RequestProduct $product
     *
     * @return ResponseTypeInterface
     */
    public function getProductHistoricRates(RequestProduct $product) {
        return $this->get(['products', $product->getProductId(), 'candles'], ResponseProductHistoricRate::class, $product->toArray());
    }

    /**
     * Get 24 hr stats for the product. volume is in base currency units. open, high, low are in quote currency units.
     *
     * @param RequestProduct $product
     *
     * @return ResponseTypeInterface
     */
    public function getProduct24HrStats(RequestProduct $product) {
        return $this->get(['products', $product->getProductId(), 'stats'], ResponseProduct24HourStats::class);
    }

    /**
     * List known currencies.
     *
     * @return string
     */
    public function getCurrencies() {
        return $this->get(['currencies'], ResponseCurrency::class);
    }

    /**
     * Get the API server time.
     *
     * @return string
     */
    public function getTime() {
        return $this->get(['time'], ResponseTime::class);
    }

    /**
     * @param array $uriParts
     * @param array $options
     *
     * @return ResponseTypeInterface
     */
    protected function put(array $uriParts, $type, array $options = []) {
        $response = $this->request(GDAXConstants::METHOD_PUT, $uriParts, $options);
        return (new ResponseContainer)->setRawData($response)->mapResponseToType($type)->getData();
    }

    /**
     * @param array  $uriParts
     * @param string $type
     * @param array  $options
     *
     * @return ResponseTypeInterface
     */
    protected function post(array $uriParts, $type, array $options = []) {
        $response = $this->request(GDAXConstants::METHOD_POST, $uriParts, $options);
        return (new ResponseContainer)->setRawData($response)->mapResponseToType($type)->getData();
    }

    /**
     * @param array  $uriParts
     * @param string $type
     * @param array  $options
     *
     * @return ResponseTypeInterface
     */
    protected function delete(array $uriParts, $type, array $options = []) {
        $response = $this->request(GDAXConstants::METHOD_DELETE, $uriParts, $options);
        return (new ResponseContainer)->setRawData($response)->mapResponseToType($type)->getData();
    }

}
