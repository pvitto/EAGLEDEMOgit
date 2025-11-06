<?php
// Determine if only content is requested (for AJAX loading)
$isContentOnly = isset($_GET['content_only']) && $_GET['content_only'] == '1';

// Initialize variables needed in both contexts (if any)
$all_clients_local = []; // Still needed if the form below uses it, which it does.

if (!$isContentOnly) {
    // --- Full Page Load ---
    require 'config.php'; // <-- AÑADE ESTO (ya incluye session_start)
    require 'check_session.php';
    if ($_SESSION['user_role'] !== 'Admin') {
        header('Location: index.php');
        exit;
    }
    require 'db_connection.php'; // <-- Esto ahora usará la zona horaria
    // ...
    // No need to fetch clients here, JS will do it
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestionar Clientes - EAGLE 3.0</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100 p-8">
    <?php // Start PHP block again if needed, or keep HTML content outside ?>
    <div class="max-w-6xl mx-auto bg-white p-6 rounded-xl shadow-lg">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Gestionar Clientes</h1>
            <a href="index.php" class="text-blue-600 hover:underline">Volver al Panel</a>
        </div>
        <div class="mb-8 p-4 border rounded-lg">
             <h2 class="text-xl font-semibold mb-4">Agregar Nuevo Cliente</h2>
             <form id="add-client-form-ajax" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <div><label for="client-name-ajax" class="block text-sm font-medium text-gray-700">Nombre</label><input type="text" id="client-name-ajax" required class="mt-1 input-style"></div>
                <div><label for="client-nit-ajax" class="block text-sm font-medium text-gray-700">NIT</label><input type="text" id="client-nit-ajax" required class="mt-1 input-style"></div>
                <div><label for="client-address-ajax" class="block text-sm font-medium text-gray-700">Dirección</label><input type="text" id="client-address-ajax" class="mt-1 input-style"></div>
                <button type="submit" class="btn-primary w-full">Guardar</button>
             </form>
        </div>
        <div>
            <h2 class="text-xl font-semibold mb-4">Clientes Existentes</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left"><th class="px-6 py-3">Nombre</th><th class="px-6 py-3">NIT</th><th class="px-6 py-3">Dirección</th><th class="px-6 py-3">Creación</th></tr>
                    </thead>
                    <tbody id="clients-table-body-ajax"><tr><td colspan="4" class="p-4 text-center text-gray-400">Cargando...</td></tr></tbody>
                </table>
            </div>
        </div>
    </div> <script>
        // Define styles commonly used
        const inputStyle = "block w-full border border-gray-300 rounded-md shadow-sm p-2";
        const btnPrimary = "bg-blue-600 text-white font-semibold py-2 px-4 rounded-md hover:bg-blue-700";
        document.querySelectorAll('.input-style').forEach(el => el.className = inputStyle);
        document.querySelectorAll('.btn-primary').forEach(el => el.className = btnPrimary);

        // Define the initialization function globally or ensure it's defined before calling
        function initializeManageClients() {
            const apiUrlClients = 'api/clients_api.php';
            const clientsTbody = document.getElementById('clients-table-body-ajax');
            const addClientForm = document.getElementById('add-client-form-ajax');

            async function fetchClientsManage() { /* ... function content ... */ }
            if (addClientForm) { addClientForm.addEventListener('submit', async function(e) { /* ... function content ... */ }); }
            fetchClientsManage();
        }
        document.addEventListener('DOMContentLoaded', initializeManageClients);
    </script>
</body>
</html>
<?php
    if (isset($conn)) $conn->close();
} else {
    // --- AJAX Content Load ---
    require 'config.php'; // <-- AÑADE ESTO
    if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Admin') {
        echo '<p class="text-red-500 p-4">Acceso no autorizado.</p>';
        exit;
    }
    require 'db_connection.php'; // <-- Esto ahora usará la zona horaria
    // ...
    // *** No HTML head/body needed here ***
?>
    <div class="max-w-6xl mx-auto bg-white p-6 rounded-xl shadow-lg">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Gestionar Clientes</h1>
            <?php /* No Back button in AJAX mode */ ?>
        </div>
        <div class="mb-8 p-4 border rounded-lg">
             <h2 class="text-xl font-semibold mb-4">Agregar Nuevo Cliente</h2>
             <form id="add-client-form-ajax" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <div><label for="client-name-ajax" class="block text-sm font-medium text-gray-700">Nombre</label><input type="text" id="client-name-ajax" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2"></div>
                <div><label for="client-nit-ajax" class="block text-sm font-medium text-gray-700">NIT</label><input type="text" id="client-nit-ajax" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2"></div>
                <div><label for="client-address-ajax" class="block text-sm font-medium text-gray-700">Dirección</label><input type="text" id="client-address-ajax" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2"></div>
                <button type="submit" class="w-full bg-blue-600 text-white font-semibold py-2 px-4 rounded-md hover:bg-blue-700">Guardar</button>
             </form>
        </div>
        <div>
            <h2 class="text-xl font-semibold mb-4">Clientes Existentes</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left"><th class="px-6 py-3">Nombre</th><th class="px-6 py-3">NIT</th><th class="px-6 py-3">Dirección</th><th class="px-6 py-3">Creación</th></tr>
                    </thead>
                    <tbody id="clients-table-body-ajax"><tr><td colspan="4" class="p-4 text-center text-gray-400">Cargando...</td></tr></tbody>
                </table>
            </div>
        </div>
    </div> <script>
        // IIFE for AJAX context
        function initializeManageClients() {
            const apiUrlClients = 'api/clients_api.php';
            const clientsTbody = document.getElementById('clients-table-body-ajax');
            const addClientForm = document.getElementById('add-client-form-ajax');

            async function fetchClientsManage() {
                if (!clientsTbody) { console.error("Client table body not found"); return; }
                try {
                    const response = await fetch(apiUrlClients);
                    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                    const clients = await response.json();
                    clientsTbody.innerHTML = '';
                    if (clients.length === 0) {
                        clientsTbody.innerHTML = '<tr><td colspan="4" class="text-center p-4 text-gray-500">No hay clientes.</td></tr>';
                    } else {
                        clients.forEach(client => {
                            const row = `
                                <tr class="border-b">
                                    <td class="px-6 py-4 font-medium">${client.name || ''}</td>
                                    <td class="px-6 py-4 font-mono">${client.nit || 'N/A'}</td>
                                    <td class="px-6 py-4">${client.address || 'N/A'}</td>
                                    <td class="px-6 py-4 text-xs">${client.created_at ? new Date(client.created_at).toLocaleString('es-CO', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'}) : ''}</td>
                                </tr>`;
                            clientsTbody.innerHTML += row;
                        });
                    }
                } catch (error) {
                    console.error('Error fetching clients:', error);
                    if (clientsTbody) clientsTbody.innerHTML = '<tr><td colspan="4" class="text-center p-4 text-red-500">Error al cargar.</td></tr>';
                }
            }

            if (addClientForm) {
                // Check if listener already exists before adding
                 if (!addClientForm.hasAttribute('data-listener-added')) {
                    addClientForm.addEventListener('submit', async function(e) {
                        e.preventDefault();
                        const nameInput = document.getElementById('client-name-ajax');
                        const nitInput = document.getElementById('client-nit-ajax');
                        const addressInput = document.getElementById('client-address-ajax');
                        if (!nameInput || !nitInput || !addressInput) return;
                        const name = nameInput.value;
                        const nit = nitInput.value;
                        const address = addressInput.value;

                        try {
                            const response = await fetch(apiUrlClients, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name, nit, address }) });
                            const result = await response.json();
                            if (!response.ok) throw new Error(result.error || `Error ${response.status}`);
                            if (result.success) { this.reset(); fetchClientsManage(); }
                            else { alert('Error: ' + result.error); }
                        } catch (error) { console.error('Error adding client:', error); alert(`Error: ${error.message}`); }
                    });
                     addClientForm.setAttribute('data-listener-added', 'true'); // Mark as added
                 }
            } else { console.error("Add client form not found"); }

            fetchClientsManage(); // Load data when script runs
        };
        // Ensure function runs after DOM is ready within the AJAX loaded content
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
             initializeManageClients();
         } else {
             document.addEventListener('DOMContentLoaded', initializeManageClients); // Fallback for direct load case (though unlikely here)
         }
    </script>
<?php
    if (isset($conn)) $conn->close();
} // *** Correctly closing the 'else' block for AJAX content ***
?>