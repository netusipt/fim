<?php
// Set headers to allow cross-origin requests
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");

// Get parameters from request
$symbol = isset($_GET['symbol']) ? $_GET['symbol'] : '';
$months = isset($_GET['months']) ? intval($_GET['months']) : 4;

// Map PSE symbols to Alpha Vantage symbols - use standard stock symbols
$avSymbols = [
    "BAACEZ" => "CEZ.PR",
    "BAAKOMB" => "KOMB.PR",
    "BAAERST" => "ERST.PR",
    "BAATELEC" => "O2.PR",
    "BAAVGP" => "VGP.PR",
    "BAAPHILIP" => "TABAK.PR",
    "BAAMONETA" => "MONETA.PR",
    "BAACOLT" => "CZG.PR"
];

// Check if symbol exists
if (empty($symbol)) {
    http_response_code(400);
    echo json_encode(["error" => "Symbol parameter is required"]);
    exit;
}

// Get Alpha Vantage symbol
$avSymbol = isset($avSymbols[$symbol]) ? $avSymbols[$symbol] : $symbol;

// Your Alpha Vantage API key
$apiKey = "SDV1N9BWJEQEHA56";

// Try to get data from Alpha Vantage
$useRealData = false;

if ($useRealData) {
    // Construct Alpha Vantage URL for weekly time series
    // Note: For Prague Stock Exchange, we might need to add market prefix
    $url = "https://www.alphavantage.co/query?function=TIME_SERIES_WEEKLY&symbol=$avSymbol&apikey=$apiKey";
    
    // Initialize cURL session
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    
    // Execute cURL session
    $jsonData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Check if request was successful
    if ($httpCode != 200 || empty($jsonData)) {
        $result = generateSimulatedData($symbol, $months);
        $result["note"] = "Using simulated data due to API error";
        echo json_encode($result);
        exit;
    }
    
    // Parse JSON data
    $data = json_decode($jsonData, true);
    
    // Check for error messages
    if (isset($data['Error Message']) || isset($data['Information']) || !isset($data['Weekly Time Series'])) {
        $result = generateSimulatedData($symbol, $months);
        $result["note"] = "Using simulated data due to API error: " . 
            (isset($data['Error Message']) ? $data['Error Message'] : 
            (isset($data['Information']) ? $data['Information'] : "No weekly time series data available"));
        echo json_encode($result);
        exit;
    }
    
    $timeSeries = $data['Weekly Time Series'];
    $dates = [];
    $prices = [];
    $returns = [];
    
    // Calculate how many weeks we need based on months
    $weeksNeeded = $months * 4.33; // Approximate weeks per month
    
    // Sort dates in descending order (newest first)
    $dateKeys = array_keys($timeSeries);
    sort($dateKeys, SORT_DESC);
    
    // Limit to the number of weeks we need
    $dateKeys = array_slice($dateKeys, 0, $weeksNeeded);
    
    // Process each date
    foreach ($dateKeys as $date) {
        $closePrice = floatval($timeSeries[$date]['4. close']);
        
        if ($closePrice <= 0) continue; // Skip invalid prices
        
        $dates[] = $date;
        $prices[] = $closePrice;
        
        if (count($prices) > 1) {
            $prevPrice = $prices[count($prices) - 2];
            $returnValue = (($closePrice / $prevPrice) - 1) * 100;
            $returns[] = $returnValue;
        } else {
            $returns[] = 0;
        }
    }
    
    // Reverse arrays to get chronological order
    $dates = array_reverse($dates);
    $prices = array_reverse($prices);
    $returns = array_reverse($returns);
    
    // Check if we have valid data
    if (empty($dates)) {
        $result = generateSimulatedData($symbol, $months);
        $result["note"] = "Using simulated data due to no valid data from API";
        echo json_encode($result);
        exit;
    }
    
    // Return JSON response
    echo json_encode([
        "dates" => $dates,
        "prices" => $prices,
        "returns" => $returns,
        "symbol" => $symbol
    ]);
} else {
    // Use simulated data for now
    $result = generateSimulatedData($symbol, $months);
    echo json_encode($result);
}

// Generate simulated data
function generateSimulatedData($symbol, $months) {
    $dates = [];
    $prices = [];
    $returns = [];
    
    // Set seed based on symbol for consistent results
    srand(crc32($symbol));
    
    // Initial price based on symbol
    $basePrice = 500 + (crc32($symbol) % 1000);
    $volatility = 0.02 + (crc32($symbol . 'vol') % 100) / 1000; // 2-12% volatility
    
    // Generate weekly data
    $today = new DateTime();
    $date = clone $today;
    $date->modify("-" . ($months + 1) . " months"); // Start a bit earlier
    
    $previousPrice = $basePrice;
    
    for ($i = 0; $i < $months * 4.33; $i++) { // ~4.33 weeks per month
        $date->modify("+7 days");
        
        // Skip if date is in the future
        if ($date > $today) break;
        
        $dateStr = $date->format('Y-m-d');
        $dates[] = $dateStr;
        
        // Simulate price movement with some randomness and trend
        $trend = sin($i / 10) * 0.005; // Add a slight cyclical trend
        $change = (mt_rand(-100, 100) / 100) * $volatility + $trend;
        $newPrice = $previousPrice * (1 + $change);
        
        $prices[] = $newPrice;
        
        // Calculate return
        if ($i > 0) {
            $returnValue = (($newPrice / $previousPrice) - 1) * 100;
            $returns[] = $returnValue;
        } else {
            $returns[] = 0;
        }
        
        $previousPrice = $newPrice;
    }
    
    return [
        "dates" => $dates,
        "prices" => $prices,
        "returns" => $returns,
        "symbol" => $symbol,
        "simulated" => true
    ];
}
?> 