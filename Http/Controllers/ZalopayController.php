<?php

namespace Modules\Gateways\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use Modules\Gateways\Traits\Processor;
use Modules\Gateways\Entities\PaymentRequest;

class ZalopayController extends Controller
{
    use Processor;

    private mixed $config_values;
    private $app_id;
    private $key1;
    private $key2;
    private string $config_mode;
    private PaymentRequest $payment;

    public function __construct(PaymentRequest $payment)
    {
        $config = $this->payment_config('zalopay', 'payment_config');
        if (!is_null($config) && $config->mode == 'live') {
            $this->config_values = json_decode($config->live_values);
        } elseif (!is_null($config) && $config->mode == 'test') {
            $this->config_values = json_decode($config->test_values);
        }

        if ($config) {
            $this->app_id = $this->config_values->app_id;
            $this->key1 = $this->config_values->key1;
            $this->key2 = $this->config_values->key2;
            $this->config_mode = ($config->mode == 'test') ? 'test' : 'live';
        }

        $this->payment = $payment;
    }

    public function payment(Request $req)
    {
        $validator = Validator::make($req->all(), [
            'payment_id' => 'required|uuid'
        ]);

        if ($validator->fails()) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_400, null, $this->error_processor($validator)), 400);
        }

        $payment_data = $this->payment::where(['id' => $req['payment_id']])->where(['is_paid' => 0])->first();
        if (!isset($payment_data)) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_204), 200);
        }

        $payer = json_decode($payment_data['payer_information']);

        $order_url = $this->config_mode == 'test' ? 'https://sandbox.zalopay.vn/v001/tpe/createorder' : 'https://sb-openapi.zalopay.vn/v2/create';
        $redirect_url = $this->config_mode == 'test' ? 'https://sandbox.zalopay.vn/checkout' : 'https://zalopay.vn/checkout';

        $order = [
            'app_id' => $this->app_id,
            'app_trans_id' => date("ymd") . '_' . $payment_data->id, // translation ID
            'app_user' => $payer->email,
            'amount' => $payment_data->payment_amount * 1000, // amount in VND
            'app_time' => round(microtime(true) * 1000), // miliseconds
            'item' => 'Payment for order ' . $payment_data->id,
            'embed_data' => json_encode(['redirecturl' => route('zalopay.callback')]),
            'mac' => hash_hmac('sha256', $this->app_id . "|" . $payment_data->id . "|" . ($payment_data->payment_amount * 1000) . "|" . round(microtime(true) * 1000) . "|" . $payer->email, $this->key1)
        ];

        $response = $this->sendRequest($order_url, $order);

        if ($response && $response->return_code == 1) {
            return Redirect::away($redirect_url . '?order_token=' . $response->order_token);
        } else {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_400, null, $response->return_message), 400);
        }
    }

    public function callback(Request $request)
    {
        $data = $request->all();
        $mac = hash_hmac('sha256', $data['data'], $this->key2);
        if ($mac === $data['mac']) {
            $this->payment::where(['transaction_id' => $data['app_trans_id']])->update([
                'payment_method' => 'zalopay',
                'is_paid' => 1,
                'transaction_id' => $data['app_trans_id'],
            ]);

            $data = $this->payment::where(['transaction_id' => $data['app_trans_id']])->first();

            if (isset($data) && function_exists($data->success_hook)) {
                call_user_func($data->success_hook, $data);
            }

            return $this->payment_response($data, 'success');
        } else {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_400, null, 'Invalid MAC'), 400);
        }
    }

    private function sendRequest($url, $data)
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        $response = curl_exec($curl);
        curl_close($curl);
        return json_decode($response);
    }
}
