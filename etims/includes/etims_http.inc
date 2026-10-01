<?php
/**********************************************************************
    eTIMS - shared outbound-HTTP helper for the Stanbest/Vibranium
    "iSale" API. Self-contained rather than reusing Communications'
    comm_http_post_json() - eTIMS is a Setup-level integration and
    shouldn't depend on the Communications module folder - but also
    needs GET (GetAllSales/GetAllProducts/etc.), which that helper
    doesn't have.
***********************************************************************/

function etims_http_request($method, $url, array $headers = array(), ?array $body = null, $timeout = 30)
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(array('Content-Type: application/json'), $headers));
	if ($body !== null)
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
	curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
	$response = curl_exec($ch);
	$error = curl_error($ch);
	$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	if ($response === false || $response === '') {
		return array('ok' => false, 'http_code' => $http_code, 'error' => $error !== '' ? $error : 'Empty response');
	}
	$data = json_decode($response, true);
	if (!is_array($data)) {
		return array('ok' => false, 'http_code' => $http_code, 'error' => 'Invalid response body', 'raw' => $response);
	}
	// Stanbest's own body carries success/failure (status:true/false) - a
	// 2xx HTTP code with status:false (e.g. a validation error) is still a
	// failure from the caller's point of view.
	$ok = $http_code >= 200 && $http_code < 300 && (!array_key_exists('status', $data) || $data['status']);
	return array('ok' => $ok, 'http_code' => $http_code, 'data' => $data);
}

function etims_http_get($url, array $headers = array(), $timeout = 30)
{
	return etims_http_request('GET', $url, $headers, null, $timeout);
}

function etims_http_post($url, array $headers, array $body, $timeout = 30)
{
	return etims_http_request('POST', $url, $headers, $body, $timeout);
}

// For the PDF report endpoints (xreport/pdf, zreport/pdf,
// zreport/periodical/pdf) - etims_http_request() always json_decode()s
// the body and fails on anything that isn't valid JSON, which a PDF
// binary response never is. Returns raw bytes, not a decoded array.
function etims_http_get_raw($url, array $headers = array(), $timeout = 30)
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
	curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
	$response = curl_exec($ch);
	$error = curl_error($ch);
	$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
	curl_close($ch);

	if ($response === false || $response === '')
		return array('ok' => false, 'http_code' => $http_code, 'error' => $error !== '' ? $error : 'Empty response');

	$ok = $http_code >= 200 && $http_code < 300 && strpos((string)$content_type, 'pdf') !== false;
	return array('ok' => $ok, 'http_code' => $http_code, 'data' => $response, 'content_type' => $content_type);
}
