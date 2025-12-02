<?php
require_once __DIR__ . '/../config.php';
require_role(['admin']);

// --- GET клиниц мэдээлэл ---
try {
    $st = db()->query("SELECT * FROM clinics ORDER BY sort_order, name");
    $clinics = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $clinics = [];
}

// --- POST үйлдэл ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $color = trim($_POST['color'] ?? '#3b82f6');

        if ($code && $name) {
            try {
                $st = db()->prepare("INSERT INTO clinics (code, name, theme_color, active, sort_order) VALUES (?, ?, ?, 1, 0)");
                $st->execute([$code, $name, $color]);
                echo json_encode(['ok' => true, 'msg' => 'Эмнэлэг амжилттай нэмэгдлээ']);
            } catch (Exception $e) {
                echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['ok' => false, 'msg' => 'Код болон нэр оруулна уу']);
        }
        exit;
    }

    if ($action === 'update') {
        $id = $_POST['id'] ?? 0;
        $name = trim($_POST['name'] ?? '');
        $color = trim($_POST['color'] ?? '#3b82f6');
        $active = isset($_POST['active']) ? 1 : 0;

        if ($id && $name) {
            try {
                $st = db()->prepare("UPDATE clinics SET name=?, theme_color=?, active=? WHERE id=?");
                $st->execute([$name, $color, $active, $id]);
                echo json_encode(['ok' => true, 'msg' => 'Эмнэлэг амжилттай засагдлаа']);
            } catch (Exception $e) {
                echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['ok' => false, 'msg' => 'ID болон нэр оруулна уу']);
        }
        exit;
    }

    if ($action === 'delete') {
        $id = $_POST['id'] ?? 0;

        if ($id) {
            try {
                $st = db()->prepare("DELETE FROM clinics WHERE id=?");
                $st->execute([$id]);
                echo json_encode(['ok' => true, 'msg' => 'Эмнэлэг амжилттай устгагдлаа']);
            } catch (Exception $e) {
                echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['ok' => false, 'msg' => 'ID алга']);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="mn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>🏥 Эмнэлгүүд — Захиалгын систем</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --secondary: #8b5cf6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #06b6d4;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #f0f4ff 0%, #faf5ff 50%, #f0fdfa 100%);
            font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
            min-height: 100vh;
        }
        
        main {
            margin-left: 250px;
            padding: 2rem;
            min-height: 100vh;
        }
        
        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            pointer-events: none;
        }
        
        .page-header h2 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .page-header p {
            opacity: 0.9;
            margin: 0;
            font-size: 0.95rem;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        
        .stat-card .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin-bottom: 1rem;
        }
        
        .stat-card.purple .stat-icon { background: linear-gradient(135deg, #ede9fe, #f3e8ff); color: #7c3aed; }
        .stat-card.green .stat-icon { background: linear-gradient(135deg, #d1fae5, #ecfdf5); color: #059669; }
        .stat-card.blue .stat-icon { background: linear-gradient(135deg, #dbeafe, #eff6ff); color: #2563eb; }
        
        .stat-card .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }
        
        .stat-card .stat-label {
            font-size: 0.875rem;
            color: #64748b;
        }
        
        /* Main Card */
        .glass-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            border: 1px solid rgba(0,0,0,0.05);
            overflow: hidden;
        }
        
        .card-header-custom {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-header-custom h5 {
            font-weight: 600;
            color: #1e293b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Table */
        .modern-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .modern-table thead th {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 1rem 1.25rem;
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            white-space: nowrap;
        }
        
        .modern-table tbody tr {
            border-bottom: 1px solid #f1f5f9;
            transition: all 0.2s;
        }
        
        .modern-table tbody tr:hover {
            background: linear-gradient(135deg, #faf5ff, #f0f4ff);
        }
        
        .modern-table tbody td {
            padding: 1rem 1.25rem;
            vertical-align: middle;
        }
        
        /* Clinic Avatar */
        .clinic-avatar {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.25rem;
            text-transform: uppercase;
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
        }
        
        .clinic-info .clinic-name {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.125rem;
        }
        
        .clinic-info .clinic-id {
            font-size: 0.75rem;
            color: #94a3b8;
        }
        
        /* Code Badge */
        .code-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
            color: #475569;
            font-family: 'Monaco', 'Consolas', monospace;
        }
        
        /* Color Preview */
        .color-preview {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .color-box {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            border: 2px solid rgba(255,255,255,0.5);
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        .color-code {
            font-size: 0.8rem;
            color: #64748b;
            font-family: 'Monaco', 'Consolas', monospace;
        }
        
        /* Status Badge */
        .status-badge {
            padding: 0.375rem 0.875rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        
        .status-badge.active {
            background: linear-gradient(135deg, #d1fae5, #ecfdf5);
            color: #059669;
        }
        
        .status-badge.inactive {
            background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
            color: #64748b;
        }
        
        /* Action Buttons */
        .btn-action {
            padding: 0.5rem 0.875rem;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }
        
        .btn-edit {
            background: linear-gradient(135deg, #fef3c7, #fffbeb);
            color: #b45309;
        }
        
        .btn-edit:hover {
            background: linear-gradient(135deg, #fde68a, #fef3c7);
            transform: translateY(-1px);
        }
        
        .btn-delete {
            background: linear-gradient(135deg, #fee2e2, #fef2f2);
            color: #dc2626;
        }
        
        .btn-delete:hover {
            background: linear-gradient(135deg, #fecaca, #fee2e2);
            transform: translateY(-1px);
        }
        
        /* Add Button */
        .btn-add {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
        }
        
        .btn-add:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
        }
        
        /* Modal Styling */
        .modal-content {
            border: none;
            border-radius: 20px;
            overflow: hidden;
        }
        
        .modal-header-custom {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 1.25rem 1.5rem;
            border: none;
        }
        
        .modal-header-custom .modal-title {
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .form-label {
            font-weight: 500;
            color: #374151;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .form-label i {
            color: var(--primary);
            font-size: 0.875rem;
        }
        
        .form-control, .form-select {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            transition: all 0.2s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }
        
        .form-control-color {
            width: 60px;
            height: 45px;
            padding: 0.25rem;
            border-radius: 10px;
        }
        
        .modal-footer-custom {
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            padding: 1rem 1.5rem;
        }
        
        .btn-cancel {
            background: #f1f5f9;
            color: #64748b;
            border: none;
            padding: 0.625rem 1.25rem;
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .btn-cancel:hover {
            background: #e2e8f0;
            color: #475569;
        }
        
        .btn-save {
            background: linear-gradient(135deg, #10b981, #34d399);
            color: white;
            border: none;
            padding: 0.625rem 1.5rem;
            border-radius: 10px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }
        
        .btn-save:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }
        
        /* Toggle Switch */
        .toggle-switch {
            position: relative;
            width: 50px;
            height: 26px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #cbd5e1;
            transition: 0.3s;
            border-radius: 26px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: 0.3s;
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        .toggle-switch input:checked + .toggle-slider {
            background: linear-gradient(135deg, #10b981, #34d399);
        }
        
        .toggle-switch input:checked + .toggle-slider:before {
            transform: translateX(24px);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #94a3b8;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            main {
                margin-left: 0;
                padding: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../partials/sidebar.php'; ?>

    <main>
        <!-- Page Header -->
        <div class="page-header">
            <h2><i class="fas fa-hospital"></i> Эмнэлгүүд</h2>
            <p>Эмнэлгийн мэдээллийг удирдах</p>
        </div>

        <!-- Stats Grid -->
        <?php
        $totalClinics = count($clinics);
        $activeClinics = count(array_filter($clinics, fn($c) => $c['active'] == 1));
        $inactiveClinics = $totalClinics - $activeClinics;
        ?>
        <div class="stats-grid">
            <div class="stat-card purple">
                <div class="stat-icon"><i class="fas fa-hospital"></i></div>
                <div class="stat-value"><?= $totalClinics ?></div>
                <div class="stat-label">Нийт эмнэлэг</div>
            </div>
            <div class="stat-card green">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-value"><?= $activeClinics ?></div>
                <div class="stat-label">Идэвхтэй</div>
            </div>
            <div class="stat-card blue">
                <div class="stat-icon"><i class="fas fa-pause-circle"></i></div>
                <div class="stat-value"><?= $inactiveClinics ?></div>
                <div class="stat-label">Идэвхгүй</div>
            </div>
        </div>

        <!-- Main Card -->
        <div class="glass-card">
            <div class="card-header-custom">
                <h5><i class="fas fa-list"></i> Эмнэлгийн жагсаалт</h5>
                <button id="addBtn" class="btn-add">
                    <i class="fas fa-plus"></i> Эмнэлэг нэмэх
                </button>
            </div>
            
            <div class="table-responsive">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>Эмнэлэг</th>
                            <th>Код</th>
                            <th>Өнгө</th>
                            <th>Статус</th>
                            <th style="text-align: right;">Үйлдэл</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clinics as $clinic): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="clinic-avatar" style="background: linear-gradient(135deg, <?= htmlspecialchars($clinic['theme_color'] ?? '#6366f1') ?>, <?= htmlspecialchars($clinic['theme_color'] ?? '#6366f1') ?>dd);">
                                            <?= mb_substr($clinic['name'], 0, 1) ?>
                                        </div>
                                        <div class="clinic-info">
                                            <div class="clinic-name"><?= htmlspecialchars($clinic['name']) ?></div>
                                            <div class="clinic-id">ID: <?= $clinic['id'] ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="code-badge"><?= htmlspecialchars($clinic['code']) ?></span>
                                </td>
                                <td>
                                    <div class="color-preview">
                                        <div class="color-box" style="background: <?= htmlspecialchars($clinic['theme_color'] ?? '#6366f1') ?>;"></div>
                                        <span class="color-code"><?= htmlspecialchars($clinic['theme_color'] ?? '#6366f1') ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="status-badge <?= $clinic['active'] ? 'active' : 'inactive' ?>">
                                        <i class="fas <?= $clinic['active'] ? 'fa-check-circle' : 'fa-times-circle' ?> me-1"></i>
                                        <?= $clinic['active'] ? 'Идэвхтэй' : 'Идэвхгүй' ?>
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <button class="btn-action btn-edit edit-btn" 
                                            data-id="<?= $clinic['id'] ?>" 
                                            data-name="<?= htmlspecialchars($clinic['name']) ?>" 
                                            data-code="<?= htmlspecialchars($clinic['code']) ?>" 
                                            data-color="<?= htmlspecialchars($clinic['theme_color'] ?? '#6366f1') ?>" 
                                            data-active="<?= $clinic['active'] ?>">
                                        <i class="fas fa-edit"></i> Засах
                                    </button>
                                    <button class="btn-action btn-delete delete-btn" 
                                            data-id="<?= $clinic['id'] ?>" 
                                            data-name="<?= htmlspecialchars($clinic['name']) ?>">
                                        <i class="fas fa-trash"></i> Хасах
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($clinics)): ?>
                            <tr>
                                <td colspan="5">
                                    <div class="empty-state">
                                        <i class="fas fa-hospital"></i>
                                        <p>Эмнэлэг байхгүй байна</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Modal — Нэмэх -->
    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" id="addForm">
                <div class="modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Эмнэлэг нэмэх</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-hospital"></i> Эмнэлгийн нэр</label>
                        <input type="text" name="name" class="form-control" placeholder="Венера" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-code"></i> Код</label>
                        <input type="text" name="code" class="form-control" placeholder="venera" required>
                        <small class="text-muted">Жижиг үсгээр, зайгүй бичнэ</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-palette"></i> Өнгө</label>
                        <div class="d-flex align-items-center gap-3">
                            <input type="color" name="color" class="form-control form-control-color" value="#6366f1">
                            <span class="color-code" id="addColorCode">#6366f1</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer-custom">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Цуцлах</button>
                    <button type="submit" class="btn-save"><i class="fas fa-check"></i> Хадгалах</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal — Засах -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" id="editForm">
                <div class="modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Эмнэлэг засах</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id">
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-hospital"></i> Эмнэлгийн нэр</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-palette"></i> Өнгө</label>
                        <div class="d-flex align-items-center gap-3">
                            <input type="color" name="color" class="form-control form-control-color">
                            <span class="color-code" id="editColorCode">#6366f1</span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-toggle-on"></i> Статус</label>
                        <div class="d-flex align-items-center gap-3">
                            <label class="toggle-switch">
                                <input type="checkbox" name="active" id="editActive">
                                <span class="toggle-slider"></span>
                            </label>
                            <span id="statusText" class="text-muted">Идэвхгүй</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer-custom">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Цуцлах</button>
                    <button type="submit" class="btn-save"><i class="fas fa-check"></i> Хадгалах</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const addModal = new bootstrap.Modal(document.getElementById('addModal'));
        const editModal = new bootstrap.Modal(document.getElementById('editModal'));

        // Color code display
        document.querySelector('#addForm input[name="color"]').addEventListener('input', function() {
            document.getElementById('addColorCode').textContent = this.value;
        });
        
        document.querySelector('#editForm input[name="color"]').addEventListener('input', function() {
            document.getElementById('editColorCode').textContent = this.value;
        });
        
        // Status toggle text
        document.getElementById('editActive').addEventListener('change', function() {
            document.getElementById('statusText').textContent = this.checked ? 'Идэвхтэй' : 'Идэвхгүй';
        });

        // Add button
        document.getElementById('addBtn').addEventListener('click', () => {
            document.getElementById('addForm').reset();
            document.getElementById('addColorCode').textContent = '#6366f1';
            addModal.show();
        });

        // Add form
        document.getElementById('addForm').addEventListener('submit', async e => {
            e.preventDefault();
            const fd = new FormData(e.target);
            fd.append('action', 'add');

            try {
                const r = await fetch('clinics.php', {method: 'POST', body: fd});
                const j = await r.json();
                if (j.ok) {
                    showToast('✅ ' + j.msg, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('⚠️ ' + (j.msg || 'Алдаа'), 'error');
                }
            } catch (err) {
                showToast('❌ Алдаа: ' + err.message, 'error');
            }
        });

        // Edit buttons
        document.querySelectorAll('.edit-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelector('#editForm input[name="id"]').value = btn.dataset.id;
                document.querySelector('#editForm input[name="name"]').value = btn.dataset.name;
                document.querySelector('#editForm input[name="color"]').value = btn.dataset.color;
                document.getElementById('editColorCode').textContent = btn.dataset.color;
                const isActive = btn.dataset.active === '1';
                document.getElementById('editActive').checked = isActive;
                document.getElementById('statusText').textContent = isActive ? 'Идэвхтэй' : 'Идэвхгүй';
                editModal.show();
            });
        });

        // Edit form
        document.getElementById('editForm').addEventListener('submit', async e => {
            e.preventDefault();
            const fd = new FormData(e.target);
            fd.append('action', 'update');

            try {
                const r = await fetch('clinics.php', {method: 'POST', body: fd});
                const j = await r.json();
                if (j.ok) {
                    showToast('✅ ' + j.msg, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('⚠️ ' + (j.msg || 'Алдаа'), 'error');
                }
            } catch (err) {
                showToast('❌ Алдаа: ' + err.message, 'error');
            }
        });

        // Delete buttons
        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (confirm(`⚠️ "${btn.dataset.name}" эмнэлгийг үнэхээр хасах уу?`)) {
                    const fd = new FormData();
                    fd.append('action', 'delete');
                    fd.append('id', btn.dataset.id);

                    try {
                        const r = await fetch('clinics.php', {method: 'POST', body: fd});
                        const j = await r.json();
                        if (j.ok) {
                            showToast('✅ ' + j.msg, 'success');
                            setTimeout(() => location.reload(), 1000);
                        } else {
                            showToast('⚠️ ' + (j.msg || 'Алдаа'), 'error');
                        }
                    } catch (err) {
                        showToast('❌ Алдаа: ' + err.message, 'error');
                    }
                }
            });
        });
        
        // Toast notification
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 1rem 1.5rem;
                background: ${type === 'success' ? 'linear-gradient(135deg, #10b981, #34d399)' : type === 'error' ? 'linear-gradient(135deg, #ef4444, #f87171)' : 'linear-gradient(135deg, #6366f1, #8b5cf6)'};
                color: white;
                border-radius: 12px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.15);
                z-index: 9999;
                animation: slideIn 0.3s ease;
                font-weight: 500;
            `;
            toast.textContent = message;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        // Add animation styles
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
