-- ============================================================
-- CACAO DATA COLLECTOR - Base de données
-- Compatibel avec MySQL (Laragon)
-- ============================================================

CREATE DATABASE IF NOT EXISTS cacao_collector CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cacao_collector;

-- ============================================================
-- TABLE: cooperatives
-- ============================================================
CREATE TABLE IF NOT EXISTS cooperatives (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(255) NOT NULL,
    localite VARCHAR(255),
    code VARCHAR(50) UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- TABLE: users (admin + inspecteurs)
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(255) NOT NULL,
    prenom VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','inspecteur') DEFAULT 'inspecteur',
    cooperative_id INT NULL,
    code_inspecteur VARCHAR(50) UNIQUE,
    actif TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cooperative_id) REFERENCES cooperatives(id) ON DELETE SET NULL
);

-- ============================================================
-- TABLE: producteurs
-- ============================================================
CREATE TABLE IF NOT EXISTS producteurs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    nom VARCHAR(255) NOT NULL,
    prenom VARCHAR(255),
    genre ENUM('Homme','Femme') NOT NULL,
    age INT,
    cooperative_id INT,
    localite VARCHAR(255),
    section VARCHAR(255),
    nb_plantations INT DEFAULT 0,
    superficie_certifiee DECIMAL(10,2) DEFAULT 0,
    annee_creation INT,
    nb_unites_certifiees INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cooperative_id) REFERENCES cooperatives(id) ON DELETE SET NULL
);

-- ============================================================
-- TABLE: fiches_profilage (Fiche A+B)
-- ============================================================
CREATE TABLE IF NOT EXISTS fiches_profilage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inspecteur_id INT NOT NULL,
    cooperative_id INT,
    producteur_id INT NOT NULL,
    date_profilage DATE NOT NULL,
    nom_communaute VARCHAR(255),
    nb_membres_hommes INT DEFAULT 0,
    nb_membres_femmes INT DEFAULT 0,
    nb_membres_total INT DEFAULT 0,
    nb_travailleurs_hommes INT DEFAULT 0,
    nb_travailleurs_femmes INT DEFAULT 0,
    nb_travailleurs_total INT DEFAULT 0,
    statut ENUM('brouillon','soumis','valide','rejete') DEFAULT 'brouillon',
    commentaire_admin TEXT,
    sync_status ENUM('local','synced') DEFAULT 'local',
    local_id VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (inspecteur_id) REFERENCES users(id),
    FOREIGN KEY (cooperative_id) REFERENCES cooperatives(id),
    FOREIGN KEY (producteur_id) REFERENCES producteurs(id)
);

-- ============================================================
-- TABLE: enfants_menage
-- ============================================================
CREATE TABLE IF NOT EXISTS enfants_menage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fiche_profilage_id INT NOT NULL,
    nom_prenom VARCHAR(255) NOT NULL,
    lien_parente ENUM('A','B','C','D','E') NOT NULL,
    lien_parente_autre VARCHAR(255),
    genre ENUM('Garçon','Fille') NOT NULL,
    age INT NOT NULL,
    extrait_naissance ENUM('Oui','Non'),
    etat_scolarisation ENUM('Scolarisé','Déscolarisé','Jamais scolarisé'),
    niveau_scolaire ENUM('A','B','C','D','E','F','G'),
    raisons_non_scolarise JSON,
    travaux_effectues JSON,
    travaille_pour ENUM('Parents','Autres'),
    travaille_pour_autre VARCHAR(255),
    jours_par_semaine INT,
    duree_journee JSON,
    manque_cours ENUM('Oui','Non'),
    raisons_travail JSON,
    solution_pour_arreter TEXT,
    FOREIGN KEY (fiche_profilage_id) REFERENCES fiches_profilage(id) ON DELETE CASCADE
);

-- ============================================================
-- TABLE: fiches_arbres (Fiche Arbres d'ombrage)
-- ============================================================
CREATE TABLE IF NOT EXISTS fiches_arbres (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inspecteur_id INT NOT NULL,
    producteur_id INT NOT NULL,
    date_collecte DATE NOT NULL,
    nb_arbres_ombrage INT DEFAULT 0,
    densite_par_hectare DECIMAL(10,2) DEFAULT 0,
    nb_arbres_deficitaires DECIMAL(10,2) DEFAULT 0,
    statut ENUM('brouillon','soumis','valide','rejete') DEFAULT 'brouillon',
    commentaire_admin TEXT,
    sync_status ENUM('local','synced') DEFAULT 'local',
    local_id VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (inspecteur_id) REFERENCES users(id),
    FOREIGN KEY (producteur_id) REFERENCES producteurs(id)
);

-- ============================================================
-- TABLE: especes_arbres
-- ============================================================
CREATE TABLE IF NOT EXISTS especes_arbres (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fiche_arbre_id INT NOT NULL,
    numero_arbre INT,
    nom_local VARCHAR(255),
    nom_botanique VARCHAR(255),
    origine ENUM('1','2') COMMENT '1=Indigène, 2=Planté',
    non_ombrage TINYINT(1) DEFAULT 0,
    nombre_total INT DEFAULT 0,
    FOREIGN KEY (fiche_arbre_id) REFERENCES fiches_arbres(id) ON DELETE CASCADE
);

-- ============================================================
-- TABLE: fiches_engrais (Fiche Engrais/Fertilisant)
-- ============================================================
CREATE TABLE IF NOT EXISTS fiches_engrais (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inspecteur_id INT NOT NULL,
    producteur_id INT NOT NULL,
    date_collecte DATE NOT NULL,
    statut ENUM('brouillon','soumis','valide','rejete') DEFAULT 'brouillon',
    commentaire_admin TEXT,
    sync_status ENUM('local','synced') DEFAULT 'local',
    local_id VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (inspecteur_id) REFERENCES users(id),
    FOREIGN KEY (producteur_id) REFERENCES producteurs(id)
);

-- ============================================================
-- TABLE: engrais_organiques
-- ============================================================
CREATE TABLE IF NOT EXISTS engrais_organiques (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fiche_engrais_id INT NOT NULL,
    source ENUM('VEGETALE','ANIMALE','COMPOSTE','COMMERCIAL') NOT NULL,
    periode_application VARCHAR(255),
    frequence_an INT,
    quantite_periode DECIMAL(10,2),
    quantite_par_ha DECIMAL(10,2),
    quantite_annuelle DECIMAL(10,2),
    unite VARCHAR(20) DEFAULT 'kg',
    FOREIGN KEY (fiche_engrais_id) REFERENCES fiches_engrais(id) ON DELETE CASCADE
);

-- ============================================================
-- TABLE: engrais_inorganiques
-- ============================================================
CREATE TABLE IF NOT EXISTS engrais_inorganiques (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fiche_engrais_id INT NOT NULL,
    nom_commercial VARCHAR(255),
    formulation_npk VARCHAR(100),
    superficie_appliquee DECIMAL(10,2),
    periode_application VARCHAR(255),
    frequence_an INT,
    nb_sacs_periode INT,
    volume_n_an DECIMAL(10,2),
    volume_p_an DECIMAL(10,2),
    volume_k_an DECIMAL(10,2),
    FOREIGN KEY (fiche_engrais_id) REFERENCES fiches_engrais(id) ON DELETE CASCADE
);

-- ============================================================
-- TABLE: pesticides
-- ============================================================
CREATE TABLE IF NOT EXISTS pesticides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fiche_engrais_id INT NOT NULL,
    type_pesticide ENUM('1','2','3','4') COMMENT '1=Insecticide,2=Fongicide,3=Herbicide,4=Rien',
    nom_commercial VARCHAR(255),
    ingredients_actifs TEXT,
    frequence_application INT,
    quantite_traitee_an DECIMAL(10,2),
    superficie_appliquee DECIMAL(10,2),
    periode_traitement VARCHAR(255),
    FOREIGN KEY (fiche_engrais_id) REFERENCES fiches_engrais(id) ON DELETE CASCADE
);

-- ============================================================
-- TABLE: notifications
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50),
    message TEXT,
    lu TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================================
-- DONNÉES INITIALES
-- ============================================================
INSERT INTO cooperatives (nom, localite, code) VALUES 
('Coopérative Pilote Cacao', 'Abidjan', 'COOP-001');

-- Admin par défaut: admin@cacao.ci / admin123
INSERT INTO users (nom, prenom, email, password, role, cooperative_id) VALUES 
('Administrateur', 'Super', 'admin@cacao.ci', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1);

-- Note: Le mot de passe hashé ci-dessus correspond à "admin123" (bcrypt)
-- Pour Laragon, recréer avec: password_hash('admin123', PASSWORD_BCRYPT)
