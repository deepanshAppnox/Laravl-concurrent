<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Log;

class FlightController extends Controller
{

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
                    if(!$decoded['success']){
                        Log::warning("$flightType response skipped due to success = false", [
                            'message' => $decoded['message'] ?? 'No message',
                        ]);
                        continue;
                    }
                    // $data = $this->normalize($flightType, $decoded);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        echo "data: " . json_encode([
                            "type" => $flightType,
                            "data" => $flightType == "flightservice"?$decoded['result']['data']:$decoded['result']['data'],
                            // "data" => $data,
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

    private function mapCabinType($cabinPref)
    {
        return match ($cabinPref) {
            "Economy"  => 3,
            "Business" => 2,
            "First"    => 1,
            default    => 0,
        };
    }

    private function normalize(string $type, array $response): array
    {
        return match ($type) {
            'flightservice'   => $this->normalizeFlightService($response['result']['data']['itineraryGroup'][0]['flights']),
            'turkishservice'  => $this->normalizeTurkishService($response['result']['data']),
            default           => [],
        };
    }

    private function normalizeFlightService(array $flights): array
    {
        if (empty($flights)) {
            return [];
        }

        return collect($flights)->map(function ($flight) {
            return [
                'itineraryId' => $flight['itineraryId'] ?? null,
                'fare' => [
                    'totalAmount' => $flight['pricingInformation'][0]['totalFare']['totalPrice'] ?? 0,
                    'currency'    => $flight['pricingInformation'][0]['totalFare']['currency'] ?? 'USD',
                    'baseFare'    => $flight['pricingInformation'][0]['totalFare']['baseFareAmount'] ?? 0,
                    'taxAmount'   => $flight['pricingInformation'][0]['totalFare']['totalTaxAmount'] ?? 0,
                ],
                'segments' => collect($flight['legs'] ?? [])->flatMap(function ($leg) {
                    return collect($leg['schedules'])->map(function ($schedule) {
                        return [
                            'origin'        => $schedule['departure']['airport'] ?? null,
                            'destination'   => $schedule['arrival']['airport'] ?? null,
                            'departureTime' => $schedule['departure']['time'] ?? null,
                            'arrivalTime'   => $schedule['arrival']['time'] ?? null,
                            'carrier'       => $schedule['carrier']['marketing'] ?? null,
                            'flightNumber'  => $schedule['carrier']['marketingFlightNumber'] ?? null,
                            'aircraft'      => $schedule['carrier']['equipment']['code'] ?? null,
                        ];
                    });
                })->values()->toArray(),
                'passengers' => collect($flight['pricingInformation'][0]['passengerInfoList'] ?? [])->map(function ($passenger) {
                    return [
                        'type'     => $passenger['passengerType'] ?? null,
                        'fare'     => $passenger['passengerTotalFare']['totalFare'] ?? 0,
                        'currency' => $passenger['passengerTotalFare']['currency'] ?? 'USD',
                        'baggage'  => $passenger['baggageInformation'] ?? [],
                        'penalties' => $passenger['penalties'] ?? [],
                        'seats' => collect($passenger['fareComponents'] ?? [])->flatMap(function ($comp) {
                            return collect($comp['segments'] ?? [])->map(function ($seg) {
                                return [
                                    'cabinType'      => $seg['cabinType'] ?? null,
                                    'seatsAvailable' => $seg['seatsAvailable'] ?? null,
                                    'bookingCode'    => $seg['bookingCode'] ?? null,
                                ];
                            });
                        })->toArray()
                    ];
                })->toArray(),
                'source' => 'flightservice'
            ];
        })->toArray();
    }



    private function normalizeTurkishService(array $response): array
    {
        if (empty($response)) {
            return [];
        }

        // If Turkish service returns multiple offers inside an array
        $offers = is_array($response) && isset($response[0]) ? $response : [$response];

        return collect($offers)->map(function ($offer) {
            return [
                'itineraryId' => $offer['OfferID'] ?? null,
                'fare' => [
                    'totalAmount' => $offer['Price']['TotalAmount'] ?? 0,
                    'currency'    => 'USD', // Adjust if your API returns currency
                    'baseFare'    => $offer['Price']['EquivAmount'] ?? 0,
                    'taxAmount'   => $offer['Price']['TaxSummary']['TotalTaxAmount'] ?? 0,
                ],
                'segments' => collect($offer['legs'] ?? [])->flatten(1)->map(function ($segment) {
                    return [
                        'origin'        => $segment['Departure']['IATA_LocationCode'] ?? null,
                        'destination'   => $segment['Arrival']['IATA_LocationCode'] ?? null,
                        'departureTime' => $segment['Departure']['AircraftScheduledDateTime'] ?? null,
                        'arrivalTime'   => $segment['Arrival']['AircraftScheduledDateTime'] ?? null,
                        'carrier'       => $segment['CarrierDesigCode'] ?? null,
                        'flightNumber'  => $segment['MarketingCarrierFlightNumberText'] ?? null,
                        'aircraft'      => $segment['CarrierAircraftType']['IATA_AircraftTypeCode'] ?? null,
                    ];
                })->toArray(),
                'passengers' => collect($offer['PaxRefIDs'] ?? [])->map(function ($refId) {
                    return [
                        'passengerRef' => $refId
                    ];
                })->toArray(),
                'baggage'  => $offer['baggageAllowance'] ?? [],
                'services' => collect($offer['serviceDefinition'] ?? [])->pluck('Name')->toArray(),
                'source'   => 'turkishservice'
            ];
        })->toArray();
    }


}
