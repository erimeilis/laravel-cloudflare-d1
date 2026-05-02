<?php

namespace EriMeilis\CloudflareD1\Http;

use EriMeilis\CloudflareD1\Database\Concerns\EscapesSqlValues;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class D1ApiClient
{
    use EscapesSqlValues;
    protected string $accountId;

    protected string $databaseId;

    protected string $apiToken;

    protected Client $httpClient;

    protected string $baseUrl = 'https://api.cloudflare.com/client/v4';

    public function __construct(string $accountId, string $databaseId, string $apiToken, ?Client $httpClient = null)
    {
        $this->accountId = $accountId;
        $this->databaseId = $databaseId;
        $this->apiToken = $apiToken;
        $this->httpClient = $httpClient ?? new Client([
            'timeout'         => 30,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * Execute a single SQL query using the /query endpoint.
     */
    public function query(string $sql, array $bindings = []): array
    {
        return $this->executeRequest('/query', [[
            'sql'    => $sql,
            'params' => $bindings,
        ]]);
    }

    /**
     * Execute a single SQL query using the /raw endpoint (40-60% faster).
     */
    public function raw(string $sql, array $bindings = []): array
    {
        return $this->executeRequest('/raw', [[
            'sql'    => $sql,
            'params' => $bindings,
        ]]);
    }

    /**
     * Execute multiple SQL statements in a single batch (10x performance improvement).
     *
     * @param array $statements Array of ['sql' => string, 'params' => array]
     *
     * @return array Results indexed by statement order
     */
    public function batch(array $statements): array
    {
        if (empty($statements)) {
            return [];
        }

        return $this->executeRequest('/raw', $statements);
    }

    /**
     * Execute HTTP request to D1 API.
     */
    protected function executeRequest(string $endpoint, array $statements): array
    {
        $url = sprintf(
            '%s/accounts/%s/d1/database/%s%s',
            $this->baseUrl,
            $this->accountId,
            $this->databaseId,
            $endpoint
        );

        // Build request body based on statement format
        // Single query: {"sql": "...", "params": [...]}
        // Batch: {"sql": "stmt1; stmt2; ..."} (params must be bound inline for batch)
        if (count($statements) === 1 && isset($statements[0]['sql'])) {
            // Single statement with optional parameters
            $body = [
                'sql' => $statements[0]['sql'],
            ];
            if (!empty($statements[0]['params'])) {
                $body['params'] = array_values($statements[0]['params']);
            }
        } else {
            // Multiple statements - bind parameters inline and join with semicolons
            $sqlStatements = array_map(function ($stmt) {
                if (is_string($stmt)) {
                    return $stmt;
                }

                $sql = $stmt['sql'];
                $params = $stmt['params'] ?? [];

                // Bind parameters inline since batch doesn't support params
                if (!empty($params)) {
                    $sql = $this->bindParametersInline($sql, $params);
                }

                return $sql;
            }, $statements);

            $body = ['sql' => implode('; ', $sqlStatements)];
        }

        try {
            $response = $this->httpClient->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => $body,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (!($body['success'] ?? false)) {
                throw new RuntimeException(
                    'D1 API request failed: '.($body['errors'][0]['message'] ?? 'Unknown error')
                );
            }

            return $body['result'] ?? [];
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                'D1 API connection failed: '.$e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Get account ID.
     */
    public function getAccountId(): string
    {
        return $this->accountId;
    }

    /**
     * Get database ID.
     */
    public function getDatabaseId(): string
    {
        return $this->databaseId;
    }

    /**
     * Bind parameters inline to SQL (for batch operations).
     */
    protected function bindParametersInline(string $sql, array $params): string
    {
        $position = 0;

        return preg_replace_callback('/\?/', function ($match) use ($params, &$position) {
            if (!isset($params[$position])) {
                return $match[0];
            }

            return $this->escapeValue($params[$position++]);
        }, $sql);
    }
}
