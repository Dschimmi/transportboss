<?php
declare(strict_types=1);

require_once 'db_connect.php';
require_once 'classes/TruckRepository.php';
require_once 'classes/OrderRepository.php';

// Prüfe, ob truck_id und order_id gesetzt sind
if (!isset($_POST['truck_id'], $_POST['order_id'])) {
    header('Location: dispatcher_board.php');
    exit;
}

$truckId = (int)$_POST['truck_id'];
$orderId = (int)$_POST['order_id'];

$truckRepo = new TruckRepository($pdo);
$orderRepo = new OrderRepository($pdo);

// 1. Auftrag dem Fahrzeug zuweisen
$orderRepo->assignToTruck($orderId, $truckId);

// 2. Zurück zur Disposition mit dem ausgewählten Fahrzeug
header("Location: dispatcher_board.php?focus_truck_id=$truckId");
exit;