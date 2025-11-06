<?php
$isContentOnly = isset($_GET['content_only']) && $_GET['content_only'] == '1';
$all_clients_local = []; // Needs this scope
$conn = null; // Initialize connection variable

try {
    // --- Connect DB and Fetch Clients (Needed in both modes) ---
    require 'config.php'; //
    require 'db_connection.php'; // This script should define $conn
    if (!$conn || $conn->connect_error) {
         throw new Exception("DB Connection Error in manage_funds.php: " . ($conn->connect_error ?? 'Unknown error'));
    }
    $clients_result = $conn->query("SELECT id, name, nit FROM clients ORDER BY name ASC");
    if ($clients_result) {
        while ($row = $clients_result->fetch_assoc()) {
            $all_clients_local[] = $row;
        }
    } else {
         throw new Exception("Error fetching clients: " . $conn->error);
    }
    // --- End DB/Client Fetch ---


    if (!$isContentOnly) {
        // --- Full Page Load ---
        require 'check_session.php'; // Assumes db_connection.php doesn't handle session/auth
        if ($_SESSION['user_role'] !== 'Admin') {
            header('Location: index.php');
             if (isset($conn) && $conn->thread_id) $conn->close(); // Close before exit
            exit;
        }
        // $conn is already established
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestionar Fondos - EAGLE 3.0</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } /* Basic Styles */
        /* Add Tailwind component classes if needed */
        .input-style { margin-top: 0.25rem; display: block; width: 100%; border: 1px solid #d1d5db; border-radius: 0.375rem; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); padding: 0.5rem; }
        .btn-primary { background-color: #2563eb; color: white; font-weight: 600; padding: 0.5rem 1rem; border-radius: 0.375rem; }
        .btn-primary:hover { background-color: #1d4ed8; }
     </style>
</head>
<body class="bg-gray-100 p-8">
<?php
    } else {
        // --- AJAX Content Load ---
        // (config.php ya se llamó al inicio del 'try')
        if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Admin') {
            echo '<p class="text-red-500 p-4">Acceso no autorizado.</p>';
            if (isset($conn) && $conn->thread_id) $conn->close(); // Close before exit
            exit;
        }
        // $conn is already established
    }
// ...
?>
    <div class="max-w-4xl mx-auto bg-white p-6 rounded-xl shadow-lg">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Gestionar Fondos de Clientes</h1>
            <?php if (!$isContentOnly): ?>
            <a href="index.php" class="text-blue-600 hover:underline">Volver al Panel</a>
             <?php endif; ?>
        </div>
    <div class="mb-8 p-4 border rounded-lg">
            <h2 class="text-xl font-semibold mb-4">Agregar Nuevo Fondo</h2>
            
            <form id="add-fund-form-ajax" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                <div>
                    <label for="client-select-ajax" class="block text-sm font-medium text-gray-700">Cliente</label>
                    <select id="client-select-ajax" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2">
                        <option value="">Seleccione...</option>
                        <?php foreach($all_clients_local as $client): ?>
                            <option value="<?php echo $client['id']; ?>"><?php echo htmlspecialchars($client['name']) . ' (NIT: ' . htmlspecialchars($client['nit'] ?? 'N/A') . ')'; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="fund-name-ajax" class="block text-sm font-medium text-gray-700">Nombre Fondo</label>
                    <input type="text" id="fund-name-ajax" placeholder="Ej: Fondo C" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2">
                </div>
                <button type="submit" class="w-full bg-blue-600 text-white font-semibold py-2 px-4 rounded-md hover:bg-blue-700">Guardar</button>
            </form>

        </div>
        <div>
            <h2 class="text-xl font-semibold mb-4">Fondos Existentes</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left"><th class="px-6 py-3">Nombre Fondo</th><th class="px-6 py-3">Cliente</th><th class="px-6 py-3">NIT</th></tr>
                    </thead>
                    <tbody id="funds-table-body-ajax"><tr><td colspan="3" class="p-4 text-center text-gray-400">Cargando...</td></tr></tbody>
                </table>
            </div>
        </div>
    </div> <?php
if (!$isContentOnly) {
    // --- Full Page Load Footer ---
?>
    <script>
         // Define initializeManageFunds globally for direct load
         function initializeManageFunds() {
             const apiUrlFunds = 'api/funds_api.php';
             const fundsTbody = document.getElementById('funds-table-body-ajax');
             const addFundForm = document.getElementById('add-fund-form-ajax');
             async function fetchFundsManage() { /* ... function content ... */ }
             if (addFundForm) { addFundForm.addEventListener('submit', async function(e) { /* ... function content ... */ }); }
             fetchFundsManage();
         }
        document.addEventListener('DOMContentLoaded', initializeManageFunds);
    </script>
</body>
</html>
<?php
} else {
    // --- AJAX Content Load Footer ---
?>
    <script>
        // Define initializeManageFunds for AJAX context
        function initializeManageFunds() {
            const apiUrlFunds = 'api/funds_api.php';
            const fundsTbody = document.getElementById('funds-table-body-ajax');
            const addFundForm = document.getElementById('add-fund-form-ajax');

            async function fetchFundsManage() {
                if(!fundsTbody) { console.error("Fund table body not found"); return; }
                try {
                    const response = await fetch(apiUrlFunds);
                    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                    const funds = await response.json();
                    fundsTbody.innerHTML = '';
                    if (funds.length === 0) {
                        fundsTbody.innerHTML = '<tr><td colspan="3" class="text-center p-4 text-gray-500">No hay fondos.</td></tr>';
                    } else {
                        funds.forEach(fund => {
                            const row = `
                                <tr class="border-b">
                                    <td class="px-6 py-4 font-medium">${fund.name || ''}</td>
                                    <td class="px-6 py-4">${fund.client_name || ''}</td>
                                    <td class="px-6 py-4 font-mono">${fund.client_nit || 'N/A'}</td>
                                </tr>`;
                            fundsTbody.innerHTML += row;
                        });
                    }
                } catch (error) {
                     console.error('Error fetching funds:', error);
                     if (fundsTbody) fundsTbody.innerHTML = '<tr><td colspan="3" class="text-center p-4 text-red-500">Error al cargar.</td></tr>';
                 }
            }

            if (addFundForm) {
                 if (!addFundForm.hasAttribute('data-listener-added')) { // Prevent duplicate listeners
                    addFundForm.addEventListener('submit', async function(e) {
                        e.preventDefault();
                        const nameInput = document.getElementById('fund-name-ajax');
                        const clientSelect = document.getElementById('client-select-ajax');
                        if (!nameInput || !clientSelect) return;
                        const name = nameInput.value;
                        const client_id = clientSelect.value;
                        if (!client_id) { alert('Por favor seleccione un cliente.'); return; }
                        try {
                            const response = await fetch(apiUrlFunds, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name, client_id }) });
                            const result = await response.json();
                            if (!response.ok) throw new Error(result.error || `Error ${response.status}`);
                            if (result.success) { this.reset(); fetchFundsManage(); }
                            else { alert('Error: ' + result.error); }
                        } catch (error) { console.error('Error adding fund:', error); alert(`Error: ${error.message}`); }
                    });
                     addFundForm.setAttribute('data-listener-added', 'true');
                 }
            } else { console.error("Add fund form not found"); }

            fetchFundsManage(); // Initial load
        };
        // Ensure execution
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
             initializeManageFunds();
         } else {
             setTimeout(initializeManageFunds, 0); // Fallback timeout
         }
    </script>
<?php
} // *** Correctly closing the 'else' block AFTER the script ***

} catch (Exception $e) {
     error_log("Error in manage_funds.php: " . $e->getMessage());
     echo '<p class="text-red-500 p-4">Error interno al procesar la solicitud.</p>';
 } finally {
     // Ensure connection from db_connection.php is closed
     if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) {
         $conn->close();
     }
 }
?>