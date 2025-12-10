<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
// Reset everything
if (isset($_GET['reset']) && $_GET['reset'] == '1') {
    unset($_SESSION['last_raw']);
    unset($_SESSION['pending_recalc']);

    // redirect to remove ?reset=1 from URL
    header("Location: boxcalc.php");
    exit;
}
require __DIR__ . '/../config.php'; // gives $pdo

// ----------------------
// Helpers
// ----------------------
function fetch_sku(PDO $pdo, string $sku) {
    $stmt = $pdo->prepare("SELECT * FROM sli WHERE sku = ?");
    $stmt->execute([$sku]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function insert_sku(PDO $pdo, $sku, $single_weight, $mstr_weight, $mstr_qty, $u_of_m) {
    $sql = "INSERT INTO sli (sku, single_weight, mstr_weight, mstr_qty, u_of_m)
            VALUES (:sku, :single_weight, :mstr_weight, :mstr_qty, :u_of_m)
            ON DUPLICATE KEY UPDATE
                single_weight = VALUES(single_weight),
                mstr_weight   = VALUES(mstr_weight),
                mstr_qty      = VALUES(mstr_qty),
                u_of_m        = VALUES(u_of_m)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':sku'           => $sku,
        ':single_weight' => $single_weight,
        ':mstr_weight'   => $mstr_weight,
        ':mstr_qty'      => $mstr_qty,
        ':u_of_m'        => strtoupper($u_of_m),
    ]);
}

// ----------------------
// Handle Add SKU modal submission
// ----------------------
if (($_POST['action'] ?? '') === 'add_sku') {
    insert_sku(
        $pdo,
        $_POST['sku'],
        $_POST['single_weight'],
        $_POST['mstr_weight'],
        $_POST['mstr_qty'],
        $_POST['u_of_m']
    );

    // After adding SKU, reload and automatically recalc
    $_SESSION['pending_recalc'] = $_SESSION['last_raw'] ?? '';
    header("Location: boxcalc.php");
    exit;
}

// ----------------------
// Handle Calculate
// ----------------------
$total_boxes = null;
$missing_sku = null;
$raw_text = '';

if (($_POST['action'] ?? '') === 'calc') {
    $raw_text = trim($_POST['raw_data'] ?? '');
    $_SESSION['last_raw'] = $raw_text;

    $lines = preg_split('/\r\n|\r|\n/', $raw_text);
    $total_boxes = 0;

    foreach ($lines as $line) {
        if (trim($line) === '') continue;

        // Primary: TAB
        $parts = explode("\t", $line);

        // Fallback: comma
        if (count($parts) < 3) {
            $parts = explode(",", $line);
        }

        if (count($parts) < 3) continue;

        $sku = trim($parts[0]);
        $qty = (int)trim($parts[2]);

        $row = fetch_sku($pdo, $sku);
        if (!$row) {
            // SKU missing -> trigger modal
            $missing_sku = $sku;
            break;
        }

        $mstr_qty = (int)$row['mstr_qty'];
        if ($mstr_qty <= 0) continue;

        $total_boxes += ceil($qty / $mstr_qty);
    }
}

// If returning from add-sku submission, redo calculation
if (isset($_SESSION['pending_recalc'])) {
    $raw_text = $_SESSION['pending_recalc'];
    unset($_SESSION['pending_recalc']);
    $_POST['action'] = 'calc';
    // Re-run calculation logic by forcing a reload
    echo "<script>location.reload();</script>";
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Box Calculator</title>
<style>
body { background:#000; color:#fff; font-family:system-ui; padding:20px; }
textarea { width:100%; height:200px; background:#111; color:#fff; border:1px solid #444; padding:10px; }
button { padding:8px 16px; border-radius:8px; margin-top:10px; }
.modal-back {
    position:fixed; inset:0; background:rgba(0,0,0,0.7);
    display:flex; align-items:center; justify-content:center;
}
.modal {
    background:#111; padding:20px; border-radius:12px; width:350px;
    border:1px solid #555;
}
.modal input { width:100%; margin-top:6px; padding:6px; background:#222; color:white; border:1px solid #444; }
</style>
<script>
document.addEventListener("DOMContentLoaded", () => {
    const modalBack = document.getElementById('skuModalBack');
    const modal     = document.getElementById('skuModal');
    const cancelBtn = document.getElementById('cancelSkuBtn');

    if (!modalBack) return; // modal not shown

    // Close modal function
    function closeModal() {
        modalBack.style.display = 'none';
    }

    // Cancel button closes modal
    if (cancelBtn) {
        cancelBtn.addEventListener('click', closeModal);
    }

    // Press ESC to close
    document.addEventListener('keydown', (e) => {
        if (e.key === "Escape") {
            closeModal();
        }
    });

    // Clicking outside modal closes it
    modalBack.addEventListener('click', (e) => {
        if (e.target === modalBack) {
            closeModal();
        }
    });
    // Validate PIN before submitting form
    const pinInput = document.getElementById('skuPin');
    const pinError = document.getElementById('pinError');
    const skuForm = modal.querySelector('form');

    skuForm.addEventListener('submit', (e) => {
        if (!pinInput) return;

        const pin = pinInput.value.trim();
        if (pin !== "9875" && pin !== "2112" && pin !== "1411") {
            e.preventDefault();
            pinError.style.display = 'block';
            return false;
        }

        pinError.style.display = 'none';
    });
});
</script>
</head>
<body>

<h1>Master Carton Calculator</h1>
<p>Paste spreadsheet rows (SKU in column 1, Quantity in column 3):</p>

<form method="post">
    <input type="hidden" name="action" value="calc">
    <textarea name="raw_data"><?= htmlspecialchars($raw_text) ?></textarea><br>
    <button type="submit">Calculate</button>
    <button type="button" onclick="window.location='boxcalc.php?reset=1'">Reset</button>
</form>

<?php if ($total_boxes !== null && !$missing_sku): ?>
    <h2>Total Master Cartons: <?= $total_boxes ?></h2>
<?php endif; ?>

<?php if ($missing_sku): ?>
<div class="modal-back" id="skuModalBack">
    <div class="modal" id="skuModal">
        <h2>Missing SKU: <?= htmlspecialchars($missing_sku) ?></h2>
        <p>Add the SKU details:</p>

        <form method="post">
            <input type="hidden" name="action" value="add_sku">
            <label>SKU</label>
            <input name="sku" value="<?= htmlspecialchars($missing_sku) ?>" readonly>

            <label>Single Weight</label>
            <input name="single_weight" type="number" step="0.0001">

            <label>Master Weight</label>
            <input name="mstr_weight" type="number" step="0.0001">

            <label>Master Quantity</label>
            <input name="mstr_qty" type="number">

            <label>Unit of Measure</label>
            <input name="u_of_m">

            <label>PIN</label>
            <input name="pin" id="skuPin" type="password" maxlength="4">
            <div id="pinError" style="color:#f44; display:none; margin-top:6px;">
                Invalid PIN.
            </div>
            <button type="submit">Save SKU</button>
            <button type="button" id="cancelSkuBtn">Cancel</button>
        </form>
    </div>
</div>
<?php endif; ?>

</body>
</html>
