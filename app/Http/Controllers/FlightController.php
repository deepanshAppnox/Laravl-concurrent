<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Log;

class FlightController extends Controller
{
    /**
     * Streams flight search data from multiple APIs as Server-Sent Events (SSE).
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function streamFlightData(Request $request)
    {
        return Response::stream(function () use ($request) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');

            $endpoints = [
                "https://flightservice.bharatcrypto.com/api/v1/flight/search" => "flightservice",
                "https://turkishservice.bharatcrypto.com/api/v1/shop/bestprice" => "turkishservice"
            ];

            $authToken = $request->header('Authorization', '');
            $sessionId = $request->header('sessionid', '');
            $sessionToken = $request->header('sessiontoken', '');

            $apiPayloads = $this->prepareApiPayloads($endpoints, $request->all());

            $this->fetchAndStreamResponses($apiPayloads, $authToken, $sessionId, $sessionToken);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive'
        ]);
    }

    /**
     * Prepares payloads for each API based on common input.
     *
     * @param array $endpoints
     * @param array $commonPayload
     * @return array
     */
    private function prepareApiPayloads(array $endpoints, array $commonPayload)
    {
        $apiPayloads = [];

        // Extract passenger types from the request
        $passengerTypes = array_keys($commonPayload['pax']);

        // Check if ONLY "Student" or "Labor" passengers exist
        $isOnlyStudentOrLabor = !array_diff($passengerTypes, ["student", "labor"]);

        foreach ($endpoints as $url => $type) {
            // Skip flightservice if only STU/LBR passengers exist
            if ($type === "flightservice" && $isOnlyStudentOrLabor) {
                $apiPayloads[$url] = ["error" => "No data found"];
                continue;
            }

            if ($type === "flightservice") {
                $apiPayloads[$url] = [
                    "dossierId"      => $commonPayload['dossierId'],
                    "dossierCode"    => $commonPayload['dossierCode'],
                    "serviceTypeId"  => 1,
                    "locale"         => $commonPayload['locale'],
                    "currency"       => $commonPayload['currency'],
                    "pax"           => $commonPayload['pax'],
                    "cabinPref"      => $commonPayload['cabinPref'],
                    "passengerTypes" => ["PNOS", "PCIL", "PINF"], // STU & LBR removed
                    "routes"         => $commonPayload['routes'],
                    "currencyCode"   => $commonPayload['currency']
                ];
            } elseif ($type === "turkishservice") {
                $apiPayloads[$url] = [
                    "dossierId"     => $commonPayload['dossierId'],
                    "serviceTypeId" => 7,
                    "dossierCode"   => $commonPayload['dossierCode'],
                    "SpecialFare"   => $commonPayload['SpecialFare'] ?? false,
                    "Pax"           => $this->mapPassengers($commonPayload['pax'], "turkishservice"),
                    "trip"          => array_map(function ($route) use ($commonPayload) {
                        return [
                            "DepartureLocation" => $route['origin'],
                            "ArrivalLocation"   => $route['destination'],
                            "DepartureDate"     => explode('T', $route['departureDate'])[0],
                            "CabinTypes"        => [
                                [
                                    "CabinTypeCode"    => $this->mapCabinType($commonPayload['cabinPref']),
                                    "PreferenceLevel"  => "Preferred"
                                ]
                            ]
                        ];
                    }, $commonPayload['routes'])
                ];
            }
        }

        return $apiPayloads;
    }


    /**
     * Sends concurrent requests to each API and streams the response as it arrives.
     *
     * @param array $apiPayloads
     * @param string $authToken
     * @param string $sessionId
     * @param string $sessionToken
     * @return void
     */
    private function fetchAndStreamResponses(array $apiPayloads, string $authToken, string $sessionId, string $sessionToken)
    {
        Log::info('Starting to fetch and stream flight responses.');

        $multiHandle = curl_multi_init();
        $curlHandles = [];

        foreach ($apiPayloads as $url => $payload) {
            $curl = curl_init();

            $jsonPayload = json_encode($payload);
            Log::debug("Sending request to $url", ['payload' => $payload]);

            curl_setopt_array($curl, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $jsonPayload,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: ' . $authToken,
                    'sessionid: ' . $sessionId,
                    'sessiontoken: ' . $sessionToken,
                ],
                CURLOPT_TIMEOUT            => 30,    // total timeout in seconds
                CURLOPT_CONNECTTIMEOUT     => 10,
                CURLOPT_LOW_SPEED_LIMIT    => 1,
                CURLOPT_LOW_SPEED_TIME     => 10,
                CURLOPT_HEADER             => false,
            ]);

            curl_multi_add_handle($multiHandle, $curl);
            $curlHandles[spl_object_id($curl)] = [
                'handle' => $curl,
                'url'    => $url,
            ];
        }

        do {
            $status = curl_multi_exec($multiHandle, $active);
            while ($info = curl_multi_info_read($multiHandle)) {
                $handle = $info['handle'];
                $key = spl_object_id($handle);
                $url = $curlHandles[$key]['url'];

                if ($info['result'] === CURLE_OK) {
                    $response = curl_multi_getcontent($handle);
                    $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);

                    Log::info("Response received from $url", [
                        'http_code'     => $httpCode,
                        'raw_response'  => $response
                    ]);

                    $flightType = str_contains($url, 'turkishservice') ? 'turkishservice' : 'flightservice';

                    $decoded = json_decode($response, true);

                    if (json_last_error() === JSON_ERROR_NONE) {
                        echo "data: " . json_encode([
                            "type" => $flightType,
                            "data" => $decoded,
                        ]) . "\n\n";
                    } else {
                        echo "data: " . json_encode([
                            "type" => $flightType,
                            "data" => [
                                "error" => "Invalid JSON from $flightType",
                                "raw" => $response
                            ]
                        ]) . "\n\n";
                    }

                    @ob_flush();
                    flush();
                    usleep(50000);

                    curl_multi_remove_handle($multiHandle, $handle);
                    curl_close($handle);
                    unset($curlHandles[$key]);
                } else {
                    Log::error("Curl error on $url", [
                        'error' => curl_error($handle),
                        'errno' => curl_errno($handle)
                    ]);

                    $flightType = str_contains($url, 'turkishservice') ? 'turkishservice' : 'flightservice';
                    echo "data: " . json_encode([
                        "type" => $flightType,
                        "data" => [
                            "error" => "Curl error: " . curl_error($handle)
                        ]
                    ]) . "\n\n";

                    @ob_flush();
                    flush();

                    curl_multi_remove_handle($multiHandle, $handle);
                    curl_close($handle);
                    unset($curlHandles[$key]);
                }
            }

            usleep(100000); // 100ms delay to avoid busy waiting
        } while ($active && $status === CURLM_OK);

        curl_multi_close($multiHandle);
        Log::info('Finished streaming all flight responses.');
    }


    /**
     * Maps passenger types to API-specific codes.
     *
     * @param array $pax
     * @param string $apiType
     * @return array
     */
    private function mapPassengers(array $pax, string $apiType)
    {
        $turkishPassengerMap = [
            "adult"   => "ADT",
            "child"   => "CHD",
            "infant"  => "INF",
            "student" => "STU",
            "labor"   => "LBR",
            "senior"  => "LNN",
            "loyalty" => "LIF"
        ];

        $flightPassengerMap = [
            "adult"  => "ADT",
            "child"  => "CHD",
            "infant" => "INF"
        ];

        $passengerList = [];

        foreach ($pax as $type => $count) {
            for ($i = 0; $i < $count; $i++) {
                if ($apiType === "turkishservice" && isset($turkishPassengerMap[$type])) {
                    $passengerList[] = ["PTC" => $turkishPassengerMap[$type]];
                } elseif ($apiType === "flightservice" && isset($flightPassengerMap[$type])) {
                    $passengerList[] = ["PTC" => $flightPassengerMap[$type]];
                }
            }
        }

        return $passengerList;
    }

    /**
     * Maps cabin preference strings to API-specific integer codes.
     *
     * @param string $cabinPref
     * @return int
     */
    private function mapCabinType($cabinPref)
    {
        return match ($cabinPref) {
            "Economy"  => 3,
            "Business" => 2,
            "First"    => 1,
            default    => 0,
        };
    }
}
