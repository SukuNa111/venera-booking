<?php
require_once __DIR__ . '/../config.php';
require_login();

$user = current_user();
$role = $user['role'] ?? '';
$isAdmin = $role === 'admin';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if ($action === 'create') {
        $topic = trim($_POST['topic'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if ($message === '') {
            $error = 'Санал хүсэлтээ товчхон бичнэ үү.';
        } else {
            try {
                $st = db()->prepare("INSERT INTO feedback (user_id, user_name, user_role, clinic_id, topic, message) VALUES (?,?,?,?,?,?)");
                $st->execute([
                    (int)$user['id'],
                    $user['name'] ?? '',
                    $role,
                    $user['clinic_id'] ?? 'venera',
                    $topic !== '' ? $topic : null,
                    $message
                ]);
                $success = 'Санал хүсэлт илгээгдлээ. Баярлалаа!';
            } catch (Exception $ex) {
                $error = 'Санал илгээхэд алдаа гарлаа: ' . $ex->getMessage();
            }
        }
    }

    if ($action === 'close' && $isAdmin) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            try {
                $upd = db()->prepare("UPDATE feedback SET status='closed' WHERE id=?");
                $upd->execute([$id]);
                $success = 'Санал хаагдлаа.';
            } catch (Exception $ex) {
                $error = 'Саналыг хаахад алдаа гарлаа: ' . $ex->getMessage();
            }
        }
    }
}

try {
    if ($isAdmin) {
        $rows = db()->query("SELECT f.*, DATE_FORMAT(f.created_at, '%Y-%m-%d %H:%i') AS created_fmt FROM feedback f ORDER BY f.created_at DESC LIMIT 50")->fetchAll();
    } else {
        $st = db()->prepare("SELECT f.*, DATE_FORMAT(f.created_at, '%Y-%m-%d %H:%i') AS created_fmt FROM feedback f WHERE user_id=? ORDER BY f.created_at DESC LIMIT 20");
        $st->execute([(int)$user['id']]);
        $rows = $st->fetchAll();
    }
} catch (Exception $ex) {
    $rows = [];
    $error = $error ?: ('Санал уншихад алдаа гарлаа: ' . $ex->getMessage());
}

?><!doctype html>
<html lang="mn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Санал хүсэлт — Venera-Dent</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #f6f7fb 0%, #eef2ff 100%);
            font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
        }
        main {
            margin-left: 250px;
            padding: 2rem;
        }
        .feedback-card {
            background: rgba(255,255,255,0.95);
            border-radius: 18px;
            box-shadow: 0 20px 45px rgba(15,23,42,0.12);
            border: 1px solid rgba(148,163,184,0.18);
        }
        .timeline {
            border-left: 2px solid rgba(148, 163, 184, 0.35);
            margin-left: 1rem;
            padding-left: 1.5rem;
        }
        .timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -1.63rem;
            top: 4px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6, #6366f1);
            box-shadow: 0 0 0 4px rgba(99,102,241,0.25);
        }
        .badge-status-open {
            background: rgba(251, 191, 36, 0.16);
            color: #d97706;
        }
        .badge-status-closed {
            background: rgba(34, 197, 94, 0.18);
            color: #047857;
        }
        textarea {
            resize: vertical;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<main>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark mb-1">💡 Санал хүсэлт</h2>
            <p class="text-muted mb-0">Эмнэлгийн үйл ажиллагааг сайжруулах санал, шүүмжлэлээ илгээх</p>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success shadow-sm"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger shadow-sm"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="feedback-card p-4">
                <h5 class="fw-semibold mb-3"><i class="fas fa-comment-dots me-2 text-primary"></i>Шинэ санал илгээх</h5>
                <form method="post" class="needs-validation" novalidate>
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label class="form-label">Гарчиг (сонголттой)</label>
                        <input type="text" name="topic" class="form-control" maxlength="120" placeholder="Жишээ нь: Ресепшний ачаалал">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Дэлгэрэнгүй</label>
                        <textarea name="message" class="form-control" rows="6" placeholder="Санал, асуудал, сайжруулалтын санаагаа энд бичнэ үү." required></textarea>
                        <div class="form-text">Санал тань зөвхөн удирдлагад харагдана.</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-paper-plane me-2"></i>Илгээх
                    </button>
                </form>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="feedback-card p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-semibold mb-0"><i class="fas fa-list-ul me-2 text-primary"></i>Сүүлд илгээсэн санал</h5>
                    <?php if ($isAdmin): ?>
                        <span class="badge bg-secondary"><i class="fas fa-user-shield me-1"></i>Админ харах горим</span>
                    <?php else: ?>
                        <span class="badge bg-info text-dark"><i class="fas fa-user me-1"></i><?= htmlspecialchars($user['name'] ?? '') ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!$rows): ?>
                    <div class="text-muted fst-italic">Одоогоор санал хараахан ирээгүй байна.</div>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($rows as $item): ?>
                            <div class="timeline-item">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <strong><?= htmlspecialchars($item['topic'] ?: 'Гарчиггүй') ?></strong>
                                    <span class="badge <?= ($item['status'] === 'closed') ? 'badge-status-closed' : 'badge-status-open' ?>">
                                        <?= $item['status'] === 'closed' ? 'Шийдсэн' : 'Нээлттэй' ?>
                                    </span>
                                </div>
                                <div class="text-muted small mb-2">
                                    <?= htmlspecialchars($item['created_fmt'] ?? '') ?> ·
                                    <?= htmlspecialchars($item['user_name'] ?: 'Тодорхойгүй хэрэглэгч') ?>
                                    <?php if (!empty($item['clinic_id'])): ?> · <?= htmlspecialchars($item['clinic_id']) ?><?php endif; ?>
                                </div>
                                <div class="bg-light rounded p-3 mb-2" style="border-left: 3px solid #6366f1;">
                                    <?= nl2br(htmlspecialchars($item['message'])) ?>
                                </div>
                                <?php if ($isAdmin && $item['status'] !== 'closed'): ?>
                                    <form method="post" class="text-end">
                                        <input type="hidden" name="action" value="close">
                                        <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-success">
                                            <i class="fas fa-check me-1"></i>Шийдсэн гэж тэмдэглэх
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
