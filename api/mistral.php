<?php
// ============================================================
// MISTRAL AI - Assistant intelligent pour Cacao Collector
// ============================================================

function callMistral($messages, $maxTokens = 1000, $temperature = 0.7) {
    $ch = curl_init('https://api.mistral.ai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . MISTRAL_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model'       => MISTRAL_MODEL,
            'messages'    => $messages,
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
        ]),
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) throw new Exception('Erreur Mistral: ' . $err);
    $data = json_decode($res, true);
    if (isset($data['error'])) throw new Exception($data['error']['message'] ?? 'Erreur API');
    return $data['choices'][0]['message']['content'] ?? '';
}

// ── Système prompt expert cacao ────────────────────────────
function getSystemPrompt($context = []) {
    $stats = '';
    if (!empty($context['stats'])) {
        $s     = $context['stats'];
        $stats = "\n\nDONNÉES ACTUELLES DE L'APPLICATION:
- Coopératives: {$s['cooperatives']}
- Inspecteurs actifs: {$s['inspecteurs']}
- Producteurs enregistrés: {$s['producteurs']}
- Fiches profilage: {$s['fiches_profilage']['total']} (dont {$s['fiches_profilage']['soumis']} en attente)
- Fiches arbres: {$s['fiches_arbres']['total']}
- Fiches engrais: {$s['fiches_engrais']['total']}
- Enfants en activité agricole: {$s['enfants_risque']}";
    }
    return "Tu es CACAO-AI, un assistant expert spécialisé dans:
1. L'agriculture cacao en Côte d'Ivoire
2. La certification et traçabilité cacao
3. La protection des enfants dans les exploitations agricoles
4. L'agronomie tropicale (fertilisation, ombrage, pesticides)
5. L'analyse des données de collecte terrain

Tu aides les administrateurs et inspecteurs de coopératives cacao à:
- Interpréter les données collectées sur le terrain
- Identifier les risques (travail des enfants, mauvaises pratiques)
- Donner des recommandations agronomiques précises
- Analyser les tendances et anomalies
- Rédiger des rapports professionnels

Réponds TOUJOURS en français. Sois précis, pratique et professionnel.
Si tu identifies un risque (ex: enfant travaillant avec des machettes), signale-le clairement.{$stats}";
}

// ── Analyse automatique d'une fiche ────────────────────────
function analyzeFiche($type, $data) {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $prompts = [
        'profilage' => "Analyse cette fiche de profilage d'un ménage producteur de cacao et identifie:
1. Les risques de travail des enfants (tâches dangereuses, impact scolaire)
2. La situation socio-économique du ménage
3. Les points d'attention prioritaires
4. Les recommandations d'action

Fiche: $json",

        'arbres' => "Analyse ces données d'arbres d'ombrage sur une plantation cacao:
1. Évalue si la densité d'ombrage est suffisante (standard: 12-18 arbres/ha)
2. Identifie les déficits et risques agronomiques
3. Recommande des espèces adaptées si nécessaire
4. Donne un score de conformité (0-100)

Données: $json",

        'engrais' => "Analyse l'utilisation des engrais et pesticides sur cette plantation cacao:
1. Évalue si les doses sont appropriées
2. Identifie les risques environnementaux ou sanitaires
3. Vérifie la conformité avec les bonnes pratiques agricoles
4. Recommande des ajustements si nécessaire

Données: $json",
    ];

    $prompt = $prompts[$type] ?? "Analyse ces données cacao: $json";
    return callMistral([
        ['role' => 'system', 'content' => getSystemPrompt()],
        ['role' => 'user',   'content' => $prompt],
    ], 800, 0.3);
}

// ── Générer rapport ────────────────────────────────────────
function generateReport($stats, $period = 'mensuel') {
    $json = json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return callMistral([
        ['role' => 'system', 'content' => getSystemPrompt(['stats' => $stats])],
        ['role' => 'user',   'content' => "Génère un rapport $period professionnel sur les activités de collecte de données cacao. Include: résumé exécutif, points clés, risques identifiés, recommandations prioritaires. Données: $json"],
    ], 1500, 0.4);
}

// ── Détecter anomalies ─────────────────────────────────────
function detectAnomalies($db) {
    $anomalies = [];

    // Enfants avec travaux dangereux
    $stmt = $db->query("SELECT COUNT(*) as n FROM enfants_menage WHERE JSON_LENGTH(travaux_effectues) > 0 AND JSON_CONTAINS(travaux_effectues, '\"I\"') OR JSON_CONTAINS(travaux_effectues, '\"J\"') OR JSON_CONTAINS(travaux_effectues, '\"K\"')");
    $r    = $stmt->fetch();
    if ($r['n'] > 0) $anomalies[] = "⚠️ {$r['n']} enfant(s) effectuant des tâches dangereuses détecté(s)";

    // Faible densité d'arbres
    $stmt = $db->query("SELECT COUNT(*) as n FROM fiches_arbres WHERE densite_par_hectare < 12 AND densite_par_hectare > 0");
    $r    = $stmt->fetch();
    if ($r['n'] > 0) $anomalies[] = "🌳 {$r['n']} plantation(s) avec densité d'ombrage insuffisante (< 12 arbres/ha)";

    // Fiches en attente depuis longtemps
    $stmt = $db->query("SELECT COUNT(*) as n FROM fiches_profilage WHERE statut='soumis' AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $r    = $stmt->fetch();
    if ($r['n'] > 0) $anomalies[] = "📋 {$r['n']} fiche(s) en attente de validation depuis plus de 7 jours";

    return $anomalies;
}
