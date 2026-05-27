<?php
require_once __DIR__ . '/lib/review_lib.php';

pz_review_init();

$token = trim((string)($_GET['t'] ?? $_POST['t'] ?? ''));
$demoMode = trim((string)($_GET['demo'] ?? $_POST['demo'] ?? ''));
$isDemoLow = in_array($demoMode, ['low', 'nemultumit', 'client_nemultumit'], true);

if ($isDemoLow) {
    $invalid = false;
    $token = 'demo-low';
    $request = [
        'id' => 0,
        'client_id' => 0,
        'rating' => 2,
        'status' => 'demo',
    ];
    $client = ['name' => 'Client demo'];
    $clientName = 'Client demo nemultumit';
} else {
    $request = $token !== '' ? pz_review_load_request_by_token($token) : [];

    if (!$request) {
        http_response_code(404);
        $invalid = true;
        $client = [];
        $clientName = 'Client';
    } else {
        $invalid = false;
        pz_review_mark_opened((int)$request['id']);
        $request = pz_review_load_request_by_token($token);
        $client = pz_review_load_client((int)($request['client_id'] ?? 0));
        $clientName = pz_review_client_name($client);
    }
}

$googleUrl = trim(pz_review_setting_get('review_google_url', ''));
$brand = pz_review_setting_get('sms_brand_name', pz_company_name());
$step = 'rating';
$error = '';

$brandInitials = strtoupper(function_exists('mb_substr') ? mb_substr($brand, 0, 2, 'UTF-8') : substr($brand, 0, 2));
$clientLabel = $clientName !== 'Client' ? $clientName : 'clientul nostru';
$allowGoogleReview = !$isDemoLow && ((int)($request['allow_google_review'] ?? 1) === 1);

if (!$invalid && !$isDemoLow && isset($_GET['google']) && $_GET['google'] === '1') {
    $currentRating = (int)($request['rating'] ?? 0);
    if ($currentRating >= 5 && $allowGoogleReview) {
        pz_review_mark_google_click((int)$request['id']);
        if ($googleUrl !== '') {
            header('Location: ' . $googleUrl);
            exit;
        }
    }
}

if (!$invalid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($isDemoLow) {
        $step = ($action === 'answers') ? 'done_low' : 'questions';
    } elseif ($action === 'rating') {
        $rating = (int)($_POST['rating'] ?? 0);
        $comment = trim((string)($_POST['rating_comment'] ?? ''));
        if ($rating < 1 || $rating > 5) {
            $error = 'Te rugam sa alegi o nota intre 1 si 5.';
        } else {
            pz_review_save_rating((int)$request['id'], $rating, $comment);
            $request = pz_review_load_request_by_token($token);
            $step = ($rating >= 5 && $allowGoogleReview) ? 'google' : ($rating >= 5 ? 'done_high_internal' : 'questions');
        }
    } elseif ($action === 'answers') {
        $answers = $_POST['answers'] ?? [];
        if (!is_array($answers)) {
            $answers = [];
        }
        pz_review_save_answers((int)$request['id'], $answers);
        $request = pz_review_load_request_by_token($token);
        $step = 'done_low';
    }
} elseif (!$invalid) {
    if ($isDemoLow) {
        $step = 'questions';
    } else {
        $rating = (int)($request['rating'] ?? 0);
        if (!empty($request['completed_at'])) {
            $step = 'done_low';
        } elseif ($rating >= 5) {
            $step = $allowGoogleReview ? 'google' : 'done_high_internal';
        } elseif ($rating >= 1 && $rating <= 4) {
            $step = 'questions';
        }
    }
}

$questions = pz_review_default_questions();
?><!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Feedback client</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<style>
:root{
    --bg:#EEF2F7;
    --surface:#FFFFFF;
    --surface-soft:#F8FAFC;
    --text:#0F172A;
    --muted:#64748B;
    --border:#E2E8F0;
    --accent:#1160B7;
    --accent-dark:#002050;
    --accent-soft:#EEF8FF;
    --success:#047857;
    --success-soft:#ECFDF5;
    --warning:#B45309;
    --warning-soft:#FFFBEB;
    --danger:#B91C1C;
    --danger-soft:#FEF2F2;
    --radius:22px;
    --shadow:0 22px 60px -34px rgba(15,23,42,.55),0 8px 24px -18px rgba(15,23,42,.28);
    font-family:'Satoshi',Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
}
*{box-sizing:border-box}
html{min-height:100%;background:var(--bg)}
body{
    margin:0;
    min-height:100vh;
    color:var(--text);
    background:
        radial-gradient(circle at top left, rgba(29,78,216,.15), transparent 32%),
        radial-gradient(circle at bottom right, rgba(14,165,233,.12), transparent 28%),
        linear-gradient(135deg,#F8FAFC 0%,#EEF2F7 52%,#E5E9F0 100%);
    padding:24px;
    display:flex;
    align-items:center;
    justify-content:center;
}
a{color:var(--accent)}
.shell{width:100%;max-width:820px}
.card{
    background:rgba(255,255,255,.96);
    border:1px solid rgba(226,232,240,.96);
    border-radius:30px;
    box-shadow:var(--shadow);
    overflow:hidden;
    backdrop-filter:blur(16px);
}
.hero{
    position:relative;
    padding:30px;
    background:
        linear-gradient(135deg,rgba(255,255,255,.98),rgba(248,250,252,.94)),
        radial-gradient(circle at top right,rgba(29,78,216,.14),transparent 36%);
    border-bottom:1px solid var(--border);
}
.hero:before{
    content:"";
    position:absolute;
    left:0;top:0;bottom:0;
    width:5px;
    background:linear-gradient(180deg,#1160B7,#0EA5E9);
}
.hero-top{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:20px}
.brand{display:flex;align-items:center;gap:14px;min-width:0}
.logo{
    width:52px;height:52px;border-radius:18px;
    background:linear-gradient(135deg,#10243E,#1160B7);
    color:#fff;display:flex;align-items:center;justify-content:center;
    font-weight:900;letter-spacing:-.06em;
    box-shadow:0 12px 26px -18px rgba(29,78,216,.75);
    flex:0 0 auto;
}
.brand-meta{min-width:0}
.eyebrow{font-size:12px;text-transform:uppercase;letter-spacing:.12em;color:var(--muted);font-weight:800;margin-bottom:3px}
.brand-name{font-weight:850;letter-spacing:-.04em;font-size:17px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.badge{
    display:inline-flex;align-items:center;gap:7px;
    border:1px solid var(--border);background:#fff;color:var(--muted);
    border-radius:999px;padding:8px 11px;font-size:12px;font-weight:800;white-space:nowrap;
}
.badge-dot{width:7px;height:7px;border-radius:999px;background:#22C55E;box-shadow:0 0 0 4px rgba(34,197,94,.12)}
h1{margin:0;font-size:32px;line-height:1.08;letter-spacing:-.055em;color:var(--text)}
.subtitle{margin:10px 0 0;color:var(--muted);font-size:15px;line-height:1.6;max-width:680px}
.body{padding:28px 30px 32px;background:linear-gradient(180deg,#fff,#F8FAFC)}
.client-box{
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    background:var(--surface-soft);border:1px solid var(--border);border-radius:18px;
    padding:13px 15px;margin:0 0 20px;
}
.client-box strong{font-size:14px}.client-box span{color:var(--muted);font-size:13px}
.notice{border-radius:18px;padding:15px 16px;margin:12px 0;font-weight:700;line-height:1.45;border:1px solid transparent}
.notice.error{background:var(--danger-soft);color:var(--danger);border-color:#FECACA}
.notice.ok{background:var(--success-soft);color:var(--success);border-color:#BBF7D0}
.notice.warn{background:var(--warning-soft);color:#92400E;border-color:#FDE68A}
.notice.demo{background:#EEF2FF;color:#3730A3;border-color:#C7D2FE}
.section-title{font-size:18px;font-weight:850;letter-spacing:-.035em;margin:0 0 8px}.section-text{margin:0 0 18px;color:var(--muted);line-height:1.55}
.stars{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin:18px 0 22px}
.star-option input{position:absolute;opacity:0;pointer-events:none}
.star-option span{
    min-height:76px;border:1px solid var(--border);border-radius:20px;
    background:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;
    cursor:pointer;transition:transform .16s ease,border-color .16s ease,box-shadow .16s ease,background .16s ease;
    user-select:none;
}
.star-option span b{font-size:27px;line-height:1;color:#F59E0B}.star-option span small{font-size:12px;color:var(--muted);font-weight:800}
.star-option span:hover{transform:translateY(-2px);border-color:#B1D6F0;box-shadow:0 14px 26px -24px rgba(15,23,42,.7)}
.star-option input:checked+span{border-color:#1160B7;box-shadow:0 0 0 4px rgba(17,96,183,.12);background:var(--accent-soft)}
textarea,select{
    width:100%;border:1px solid var(--border);border-radius:16px;background:#fff;
    padding:13px 14px;font:inherit;color:var(--text);outline:none;
}
textarea{min-height:96px;resize:vertical;line-height:1.5}
textarea:focus,select:focus{border-color:#93C5FD;box-shadow:0 0 0 4px rgba(29,78,216,.09)}
.question-card{background:#fff;border:1px solid var(--border);border-radius:20px;padding:16px;margin:12px 0;box-shadow:0 8px 22px -22px rgba(15,23,42,.55)}
.question-label{display:block;font-weight:800;letter-spacing:-.015em;margin:0 0 12px;color:var(--text)}
.score-row{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}.score-row label input{position:absolute;opacity:0;pointer-events:none}
.score-row label span{
    display:flex;align-items:center;justify-content:center;height:42px;border:1px solid var(--border);border-radius:14px;background:#fff;
    cursor:pointer;font-weight:850;transition:.15s;color:var(--text)
}
.score-row label span:hover{border-color:#B1D6F0;background:#F8FAFC}.score-row input:checked+span{background:var(--accent-soft);border-color:var(--accent);color:var(--accent-dark);box-shadow:0 0 0 4px rgba(17,96,183,.10)}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:22px}.btn{
    border:0;border-radius:16px;background:linear-gradient(135deg,#1160B7,#173A76);color:#fff;
    font-weight:850;padding:14px 18px;min-height:48px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;cursor:pointer;
    box-shadow:0 16px 28px -22px rgba(29,78,216,.95);letter-spacing:-.015em
}
.btn:hover{filter:brightness(1.04)}.btn.secondary{background:#fff;color:var(--text);border:1px solid var(--border);box-shadow:none}
.google-box{background:linear-gradient(135deg,#F8FAFC,#EFF6FF);border:1px solid var(--border);border-radius:22px;padding:20px;margin-top:16px}
.google-title{font-size:20px;font-weight:900;letter-spacing:-.04em;margin:0 0 6px}.google-box p{color:var(--muted);line-height:1.55;margin:0 0 16px}
.fine{font-size:12px;color:var(--muted);margin-top:16px;line-height:1.55}.divider{height:1px;background:var(--border);margin:22px 0}
.footer-note{text-align:center;color:var(--muted);font-size:12px;margin-top:14px;line-height:1.45}.mini{font-size:13px;color:var(--muted)}
@media(max-width:680px){
    body{padding:12px;align-items:flex-start}.shell{max-width:100%}.card{border-radius:24px}.hero,.body{padding:22px 18px}.hero-top{flex-direction:column}.badge{align-self:flex-start}h1{font-size:27px}.stars{gap:7px}.star-option span{min-height:62px;border-radius:16px}.star-option span b{font-size:23px}.star-option span small{font-size:11px}.score-row{gap:6px}.score-row label span{height:40px}.client-box{align-items:flex-start;flex-direction:column}.actions .btn{width:100%}
}

/* ══ DS v2.4 fixes — feedback (flat, fara glassmorphism) ══ */
body{
    background: #F8FAFC !important;
}
.card{
    background: #FFFFFF !important;
    border: 1px solid #E2E8F0 !important;
    border-radius: 8px !important;
    box-shadow: none !important;
    backdrop-filter: none !important;
    -webkit-backdrop-filter: none !important;
}
.hero{
    background: #FFFFFF !important;
    border-bottom: 1px solid #E2E8F0 !important;
}
.hero:before{ display: none !important; }
.logo{
    background: #2563EB !important;
    box-shadow: none !important;
}
.body{ background: #FFFFFF !important; }
.btn{
    background: #2563EB !important;
    box-shadow: none !important;
    border-radius: 8px !important;
}
.btn:hover{ background: #1E3A8A !important; filter: none !important; }
.btn.secondary{ background: #FFFFFF !important; border: 1px solid #E2E8F0 !important; }
.google-box{
    background: #EFF6FF !important;
    border: 1px solid #BFDBFE !important;
    border-radius: 8px !important;
}
.question-card{ box-shadow: none !important; border-radius: 8px !important; }
.star-option span{ border-radius: 8px !important; }
.star-option input:checked+span{ box-shadow: none !important; background: #EFF6FF !important; border-color: #2563EB !important; }
.star-option span:hover{ box-shadow: none !important; transform: none !important; border-color: #BFDBFE !important; }
.score-row input:checked+span{ box-shadow: none !important; }
.notice{ border-radius: 4px !important; }
.client-box{ border-radius: 8px !important; }
textarea, select{ border-radius: 4px !important; }
textarea:focus, select:focus{ box-shadow: 0 0 0 3px #EFF6FF !important; border-color: #2563EB !important; }
</style>
</head>
<body>
<div class="shell">
    <div class="card">
        <div class="hero">
            <div class="hero-top">
                <div class="brand">
                    <div class="logo"><?= pz_review_h($brandInitials) ?></div>
                    <div class="brand-meta">
                        <div class="eyebrow">Feedback client</div>
                        <div class="brand-name"><?= pz_review_h($brand) ?></div>
                    </div>
                </div>
                <div class="badge"><span class="badge-dot"></span> Formular securizat</div>
            </div>
            <h1>Cum a fost experienta?</h1>
        </div>

        <div class="body">
            <?php if ($invalid): ?>
                <div class="notice error">Linkul de feedback este invalid sau a expirat.</div>
                <p class="section-text">Vă rugăm sa ne contactati direct dacă doriti sa ne transmiteti o observatie despre intervenție.</p>
            <?php else: ?>
                <div class="client-box">
                    <div><strong><?= pz_review_h($clientLabel) ?></strong><br><span>Solicitare feedback după intervenție</span></div>
                    <div class="mini">Durata estimata: 1 minut</div>
                </div>

                <?php if ($error !== ''): ?><div class="notice error"><?= pz_review_h($error) ?></div><?php endif; ?>
                <?php if ($isDemoLow): ?><div class="notice demo">Mod demonstrativ intern: asa vede clientul care a acordat sub 5 stele. Raspunsurile nu se salveaza.</div><?php endif; ?>

                <?php if ($step === 'rating'): ?>
                    <form method="post">
                        <input type="hidden" name="t" value="<?= pz_review_h($token) ?>">
                        <input type="hidden" name="action" value="rating">
                        <p class="section-title">Alegeți o nota de la 1 la 5</p>
                        <p class="section-text">5 inseamna ca experienta a fost foarte buna.</p>
                        <div class="stars" aria-label="Rating">
                            <?php for ($i=1; $i<=5; $i++): ?>
                                <label class="star-option">
                                    <input type="radio" name="rating" value="<?= $i ?>">
                                    <span><b>★</b><small><?= $i ?>/5</small></span>
                                </label>
                            <?php endfor; ?>
                        </div>
                        <label class="question-label" for="rating_comment">Comentariu optional</label>
                        <textarea id="rating_comment" name="rating_comment" placeholder="Scrieti aici orice observatie doriti sa ne transmiteti."></textarea>
                        <div class="actions"><button class="btn" type="submit">Trimite feedback</button></div>
                        <div class="fine">Feedbackul trimis aici este folosit pentru controlul calitatii serviciilor noastre.</div>
                    </form>

                <?php elseif ($step === 'google'): ?>
                    <div class="notice ok">Va multumim pentru apreciere!</div>
                    <div class="google-box">
                        <p class="google-title">Ne bucuram ca experienta a fost foarte buna.</p>
                        <p>Ne-ar ajuta mult dacă ati lasa aceeași apreciere si pe Google. Publicarea reviewului se face doar de catre dvs., in contul Google.</p>
                        <?php if ($googleUrl !== '' && $allowGoogleReview): ?>
                            <div class="actions"><a class="btn" href="feedback.php?t=<?= urlencode($token) ?>&google=1">Lasa review pe Google</a></div>
                        <?php else: ?>
                            <div class="notice warn">Linkul Google Review nu este configurat in platforma.</div>
                        <?php endif; ?>
                    </div>
                    <p class="fine">Mulțumim ca ati ales <?= pz_review_h($brand) ?>.</p>

                <?php elseif ($step === 'done_high_internal'): ?>
                    <div class="notice ok">Va multumim pentru feedback. Raspunsul dumneavoastra a fost inregistrat.</div>
                    <p class="section-text">Acest formular este folosit pentru controlul intern al calitatii serviciilor. Va multumim pentru timpul acordat.</p>

                <?php elseif ($step === 'questions'): ?>
                    <div class="notice warn">Mulțumim. Ne pare rau ca experienta nu a fost perfecta.</div>
                    <p class="section-title">Spuneti-ne ce putem imbunatati</p>
                    <p class="section-text">Acest formular este intern, nu se publica pe Google si ajunge la echipa de management pentru verificare si contactare, dacă este cazul.</p>
                    <form method="post">
                        <input type="hidden" name="t" value="<?= pz_review_h($token) ?>">
                        <?php if ($isDemoLow): ?><input type="hidden" name="demo" value="low"><?php endif; ?>
                        <input type="hidden" name="action" value="answers">
                        <?php foreach ($questions as $key => $q): ?>
                            <div class="question-card">
                                <label class="question-label"><?= pz_review_h($q['label']) ?></label>
                                <?php if ($q['type'] === 'score'): ?>
                                    <div class="score-row">
                                        <?php for ($i=1; $i<=5; $i++): ?>
                                            <label><input type="radio" name="answers[<?= pz_review_h($key) ?>]" value="<?= $i ?>"><span><?= $i ?></span></label>
                                        <?php endfor; ?>
                                    </div>
                                <?php elseif ($q['type'] === 'yesno'): ?>
                                    <select name="answers[<?= pz_review_h($key) ?>]">
                                        <option value="">Alegeți</option>
                                        <option value="Da">Da</option>
                                        <option value="Nu">Nu</option>
                                    </select>
                                <?php else: ?>
                                    <textarea name="answers[<?= pz_review_h($key) ?>]" placeholder="Scrieti raspunsul aici"></textarea>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <div class="actions"><button class="btn" type="submit">Trimite formularul</button></div>
                        <div class="fine">Pentru note sub 5 stele, mesajul rămâne intern si este folosit pentru rezolvarea situatiei.</div>
                    </form>

                <?php elseif ($step === 'done_low'): ?>
                    <div class="notice ok">Va multumim. Formularul a fost trimis.</div>
                    <p class="section-text">Feedbackul dvs. va fi analizat intern. Dacă ati solicitat contactarea, un reprezentant va reveni catre dvs.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="footer-note">Pagina optimizata pentru mobil. Nu este necesara autentificarea in platforma.</div>
</div>
</body>
</html>
