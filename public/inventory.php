<?php
require_once __DIR__ . '/../config.php';
require_role(['admin', 'reception', 'doctor']);

$user = current_user();
$isAdmin = in_array($user['role'], ['admin', 'super_admin']);

$pageTitle = "Материалын бүртгэл (Inventory)";
?>
<!doctype html>
<html lang="mn">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $pageTitle ?> — Venera-Dent</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="css/style.css" rel="stylesheet">
  <style>
    .inventory-card {
      background: white;
      border-radius: 1.25rem;
      border: 1px solid #e2e8f0;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.04);
      padding: 1.5rem;
      margin-bottom: 2rem;
    }
    .data-table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0 8px;
    }
    .data-table th {
      padding: 1rem;
      font-weight: 700;
      color: #64748b;
      text-transform: uppercase;
      font-size: 0.75rem;
      letter-spacing: 0.05em;
    }
    .data-table tr {
      background: #f8fafc;
      transition: all 0.2s;
    }
    .data-table tr:hover {
      background: #f1f5f9;
      transform: translateY(-2px);
    }
    .data-table td {
      padding: 1rem;
      border: none;
    }
    .data-table td:first-child { border-radius: 12px 0 0 12px; }
    .data-table td:last-child { border-radius: 0 12px 12px 0; }
    
    .stock-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 0.4rem 0.75rem;
      border-radius: 10px;
      font-weight: 700;
      font-size: 0.85rem;
    }
    .stock-low { background: #fee2e2; color: #ef4444; }
    .stock-ok { background: #ecfdf5; color: #10b981; }
    
    .category-chip {
      background: #eff6ff;
      color: #3b82f6;
      padding: 0.25rem 0.6rem;
      border-radius: 6px;
      font-size: 0.75rem;
      font-weight: 600;
    }
  </style>
</head>
<body>
  <?php include __DIR__ . '/../partials/sidebar.php'; ?>

  <main>
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h2 class="fw-bold"><i class="fas fa-boxes me-2 text-primary"></i>Материалын бүртгэл</h2>
      <?php if ($isAdmin): ?>
      <button class="btn btn-primary px-4 shadow-sm" style="border-radius: 12px;" onclick="showAddInventory()">
        <i class="fas fa-plus me-2"></i>Шинэ материал нэмэх
      </button>
      <?php endif; ?>
    </div>

    <div class="inventory-card">
      <div class="row g-3 mb-4">
        <div class="col-md-6">
          <div class="input-group">
            <span class="input-group-text bg-white border-end-0" style="border-top-left-radius: 12px; border-bottom-left-radius: 12px;">
              <i class="fas fa-search text-muted"></i>
            </span>
            <input type="text" id="invSearch" class="form-control border-start-0" placeholder="Материалын нэрээр хайх..." style="border-top-right-radius: 12px; border-bottom-right-radius: 12px;">
          </div>
        </div>
        <div class="col-md-3">
          <select id="filterCategory" class="form-select" style="border-radius: 12px;">
            <option value="">Бүх төрөл</option>
            <option value="Material">Материал</option>
            <option value="Pharmacy">Эмийн сан</option>
            <option value="Other">Бусад</option>
          </select>
        </div>
      </div>

      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Нэр / Хэмжээ</th>
              <th>Төрөл</th>
              <th>Үлдэгдэл</th>
              <th class="text-end">Нэгж үнэ</th>
              <th class="text-end">Үйлдэл</th>
            </tr>
          </thead>
          <tbody id="inventoryList">
            <tr><td colspan="5" class="text-center py-5"><i class="fas fa-spinner fa-spin me-2"></i>Ачаалж байна...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </main>

  <!-- Inventory Modal -->
  <div class="modal fade" id="inventoryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow-lg" style="border-radius: 1.5rem;">
        <div class="modal-header border-0 pb-0">
          <h5 class="fw-bold" id="invModalTitle">Материал нэмэх</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form id="inventoryForm">
          <input type="hidden" name="id" id="invId">
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label small fw-bold">Материалын нэр</label>
              <input type="text" name="name" id="invName" class="form-control" required style="border-radius: 0.75rem;">
            </div>
            <div class="row">
              <div class="col-6 mb-3">
                <label class="form-label small fw-bold">Төрөл</label>
                <select name="category" id="invCategory" class="form-select" style="border-radius: 0.75rem;">
                  <option value="Material">Материал</option>
                  <option value="Pharmacy">Эмийн сан</option>
                  <option value="Other">Бусад</option>
                </select>
              </div>
              <div class="col-6 mb-3">
                <label class="form-label small fw-bold">Хэмжих нэгж</label>
                <input type="text" name="unit" id="invUnit" class="form-control" placeholder="ш, мл, гр" style="border-radius: 0.75rem;">
              </div>
            </div>
            <div class="row">
              <div class="col-6 mb-3">
                <label class="form-label small fw-bold">Нэгж үнэ (₮)</label>
                <input type="number" name="unit_price" id="invPrice" class="form-control" value="0" style="border-radius: 0.75rem;">
              </div>
              <div class="col-6 mb-3">
                <label class="form-label small fw-bold">Одоо байгаа тоо</label>
                <input type="number" step="0.01" name="stock_quantity" id="invStock" class="form-control" value="0" style="border-radius: 0.75rem;">
              </div>
            </div>
          </div>
          <div class="modal-footer border-0">
            <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" style="border-radius: 0.75rem;">Болих</button>
            <button type="submit" class="btn btn-primary px-4" style="border-radius: 0.75rem;">Хадгалах</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    let inventoryData = [];
    const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;

    async function loadInventory() {
      try {
        const res = await fetch('api.php?action=get_inventory&t=' + Date.now());
        const data = await res.json();
        if (data.ok) {
          inventoryData = data.data;
          renderInventory();
        }
      } catch (e) { console.error('Load inventory error:', e); }
    }

    function renderInventory() {
      const list = document.getElementById('inventoryList');
      const search = document.getElementById('invSearch').value.toLowerCase();
      const cat = document.getElementById('filterCategory').value;

      const filtered = inventoryData.filter(i => {
        const matchSearch = i.name.toLowerCase().includes(search);
        const matchCat = !cat || i.category === cat;
        return matchSearch && matchCat;
      });

      if (!filtered.length) {
        list.innerHTML = '<tr><td colspan="5" class="text-center py-5 text-muted">Материал олдсонгүй</td></tr>';
        return;
      }

      list.innerHTML = filtered.map(item => `
        <tr>
          <td>
            <div class="fw-bold text-dark">${item.name}</div>
            <div class="small text-muted">${item.unit || ''}</div>
          </td>
          <td><span class="category-chip">${item.category}</span></td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <span class="stock-badge ${item.stock_quantity <= 5 ? 'stock-low' : 'stock-ok'}">
                <i class="fas ${item.stock_quantity <= 5 ? 'fa-exclamation-triangle' : 'fa-check'}"></i>
                ${item.stock_quantity}
              </span>
              <button class="btn btn-sm btn-outline-secondary p-1 border-0" onclick="updateStock(${item.id}, ${item.stock_quantity})" title="Үлдэгдэл засах"><i class="fas fa-edit"></i></button>
            </div>
          </td>
          <td class="text-end fw-bold text-success">${new Intl.NumberFormat('mn-MN').format(item.unit_price)}₮</td>
          <td class="text-end">
            <div class="d-flex justify-content-end gap-2">
              ${IS_ADMIN ? `
              <button class="btn btn-sm btn-light" onclick="editInventory(${JSON.stringify(item).replace(/"/g, '&quot;')})" style="border-radius: 8px;">
                <i class="fas fa-pencil-alt text-primary"></i>
              </button>
              <button class="btn btn-sm btn-light" onclick="deleteInventory(${item.id})" style="border-radius: 8px;"><i class="fas fa-trash text-danger"></i></button>
              ` : ''}
            </div>
          </td>
        </tr>
      `).join('');
    }

    document.getElementById('invSearch').addEventListener('input', renderInventory);
    document.getElementById('filterCategory').addEventListener('change', renderInventory);

    window.showAddInventory = function() {
      document.getElementById('inventoryForm').reset();
      document.getElementById('invId').value = '';
      document.getElementById('invModalTitle').textContent = 'Шинэ материал нэмэх';
      new bootstrap.Modal(document.getElementById('inventoryModal')).show();
    };

    window.editInventory = function(item) {
      document.getElementById('invId').value = item.id;
      document.getElementById('invName').value = item.name;
      document.getElementById('invCategory').value = item.category;
      document.getElementById('invUnit').value = item.unit;
      document.getElementById('invPrice').value = item.unit_price;
      document.getElementById('invStock').value = item.stock_quantity;
      document.getElementById('invModalTitle').textContent = 'Материал засах';
      new bootstrap.Modal(document.getElementById('inventoryModal')).show();
    };

    window.updateStock = async function(id, current) {
      const newVal = prompt('Шинэ үлдэгдлийг оруулна уу:', current);
      if (newVal === null || newVal === '') return;
      
      const fd = new FormData();
      fd.append('action', 'update_stock');
      fd.append('id', id);
      fd.append('quantity', newVal);
      
      const res = await fetch('api.php', { method: 'POST', body: fd });
      if ((await res.json()).ok) loadInventory();
    };

    window.deleteInventory = async function(id) {
      if (!confirm('Энэ материалыг устгах уу?')) return;
      const fd = new FormData();
      fd.append('action', 'update_inventory');
      fd.append('id', id);
      fd.append('is_active', 0);
      const res = await fetch('api.php', { method: 'POST', body: fd });
      if ((await res.json()).ok) loadInventory();
    };

    document.getElementById('inventoryForm').addEventListener('submit', async function(e) {
      e.preventDefault();
      const fd = new FormData(this);
      fd.append('action', 'save_inventory');
      const res = await fetch('api.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (data.ok) {
        bootstrap.Modal.getInstance(document.getElementById('inventoryModal')).hide();
        loadInventory();
      } else alert('Алдаа: ' + data.msg);
    });

    loadInventory();
  </script>
</body>
</html>
