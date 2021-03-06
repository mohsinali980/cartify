<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require APPPATH . '/libraries/REST_Controller.php';

class Orders extends REST_Controller {
	public function __construct() {
		parent::__construct();
		$this->load->model('orders_model');
	}
	public function checkout_get($cart_id = null) {
		
		$response=[];
		try {
			$token = $this->orders_model->get_token_by_cart($cart_id)['id'];
			if(!$token) throw new Exception("Invalid cart_id");
			$response['cart'] = $this->get_cart($cart_id, "Success", false, $token);
			$this->load->database();
			$response['countries'] = $this->db->query("SELECT * FROM countries WHERE 1")->result_array();
		}
		catch(Exception $e) {
			$response = [
				"status" => false,
				"message" => $e->getMessage()
			];
			$this->set_response($response);
			return;
		}
		$response['title'] = "Cart";
		$this->set_response($response);
		// $this->load->view('auth/customers/checkout', $response);
		
	}
	public function get_cart($cart=null, $successMsg="success", $admin=false, $token=null) {
		try {
			$public_key = token(true, $token);
			if(!$public_key) throw new Exception("Authorization header missing");
			if(!$cart)  {
				// throw new Exception("cart_id is required");
				$cart = "cart_".uniqid();
			}
			$res = $this->orders_model->get_cart($cart, $public_key['store_id']);
			if(!$res) {
				$res = $this->orders_model->create_cart($cart, $public_key['store_id']);
				if($res) {
					$res = [
						"id" => $cart,
						"status" => "in_cart"
					];
				}
			}
			else if($res['status'] != 'in_cart' && !$admin) throw new Exception("This cart_id has already been processed");
			$response = [
				'status'=>true,
				'message'=>$successMsg,
				'data'=> $res
			];
			$tmp=$this->orders_model->get_order_products($cart, $public_key['store_id']);
			/** Products fetch from products model */
			// $tmpProducts = [];
			// foreach ($tmp as $key => $value) {
			// 	$this->load->model('products_model');
			// 	$product = $this->products_model->get_single($value['id'], $public_key['store_key']);
			// 	array_push($tmpProducts, $product);
			// }
			$response['data']['products'] = $tmp?$tmp : [];
			$this->load->model("auth_model");
			$response['data']['store'] = $this->auth_model->get_store_by_key($public_key['store_id']);
		}
		catch (Exception $e) {
			$response = [
				'status'=>false, 
				'message'=>$e->getMessage(), 
			];
		}
		finally {
			return ($response);
		}
	}
	public function cart_get($cart=null) {
		$this->set_response($this->get_cart($cart, "Cart fetched", false)); // false for not admin
	}
	public function update_order_post($cartParam = null) {
		$this->set_response($this->update_order($cartParam, false)); // false for not admin
	}
	// Need to update it
	function update_order($cartParam, $adminOrder = false) {
		try {
			$pk = token(true);
			$products = $this->input->post('cart')['products'] ? $this->input->post('cart')['products'] : array();
			// if(!$products) throw new Exception("Products body params missing ");
			$data = $this->get_cart($cartParam, "Cart updated",$adminOrder);
			if(!$data['status']) throw new Exception($data['message']);
			$tmpProducts = $data['data']['products'];
			/** Delete existing products in orders from the db  */
			foreach ($data['data']['products'] as $key => $value) {
				$this->orders_model->delete_product_from_order(
					$value['id'],
					$data['data']['id']
				);
			}
			/** Consolidate new cart to add in the order */
			$toAddProducts = [];
			foreach ($products as $key => $value) {
				$flag = $this->in_array($toAddProducts, $value['id']);
				if($flag !== -1) {
					$toAddProducts[$flag]['qty'] += $value['qty'];
				}
				else {
					array_push($toAddProducts, $value);
				}
			}
			/** Insert new products passed by user */
			foreach ($toAddProducts as $key => $value) {
				$this->load->model('products_model');
				$product = $this->products_model->get_single($value['id'], $pk['store_key']);
				if($product) {
					array_push($toAddProducts, $product);
					$originalPrice = $product['compare_price'] ? $product['compare_price'] : $product['price'];
					$this->orders_model->add_to_cart([
						$value['id'],
						$value['qty'],
						$product['compare_price'] ? $product['compare_price'] : $product['price'],
						$product['price'],
						$cartParam
					]);
				}
			}
			$data['new_cart'] = $toAddProducts;
			/** Refreash the cart */
			$response = $this->get_cart($cartParam, "Cart updated",$adminOrder); 
		}
		catch (Exception $e) {
			$response = [
				'status' => false,
				'message' => $e->getMessage()
			];
		}
		finally {
			return ($response);
		}
	}
	function in_array($arr, $id) {
		$flag = -1;
		foreach ($arr as $key => $value) {
			if($value['id'] === $id) {
				$flag = $key;
				break;
			}
		}
		return $flag;
	}
}