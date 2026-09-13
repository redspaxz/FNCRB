<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Lightweight bilingual UI (EN/FR). Language is stored in the session and
 * switched via ?lang=fr|en on any URL. t('key') returns the active string.
 */
final class Lang
{
    public const DEFAULT = 'en';
    private const ALLOWED = ['en', 'fr'];

    private static string $lang = self::DEFAULT;

    private const STRINGS = [
        'en' => [
            // generic
            'app_name' => 'First National Credit Registry Bureau',
            'sign_in' => 'Sign in',
            'sign_out' => 'Sign out',
            'email' => 'Email',
            'password' => 'Password',
            'search' => 'Search',
            'submit' => 'Submit',
            'close_window' => 'Close window',
            'back_login' => 'Back to sign in',
            'language' => 'Language',
            'welcome' => 'Welcome',
            // nav
            'nav_dashboard' => 'Dashboard',
            'nav_inquiry' => 'Credit Inquiry',
            'nav_borrowers' => 'Borrowers',
            'nav_loans' => 'Loan Portfolio',
            'nav_collateral' => 'Collateral',
            'nav_incidents' => 'Incidents',
            'nav_compliance' => 'Compliance',
            'nav_audit' => 'Audit Trail',
            'nav_section_registry' => 'Registry',
            'nav_section_regulatory' => 'Regulatory',
            // login
            'login_title' => 'First National Credit Registry Bureau',
            'login_sub' => 'Central Credit Registry — Cameroon (CEMAC)',
            'login_terms_label' => 'I have read and accept the',
            'login_terms_link' => 'Terms & Conditions',
            'login_terms_suffix' => 'of the FNCRB Central Credit Registry.',
            'login_note' => 'Access restricted to authorized registry participants. All access attempts are logged and audited.',
            'err_credentials' => 'Email and password are required.',
            'err_invalid' => 'Invalid credentials.',
            'err_throttled' => 'Too many failed attempts. Account temporarily locked — try again later.',
            'err_terms' => 'You must review and accept the Terms & Conditions before signing in.',
            'err_account' => 'Account is',
            'err_contact_admin' => '. Contact your administrator.',
            // landing
            'landing_lead' => 'Central Credit Registry for Cameroon (CEMAC) — mitigating cross-institutional credit risk across Category 1, 2 & 3 Microfinance Institutions and commercial banks.',
            'landing_reg' => 'Regulatory framework:',
            'landing_f1' => 'Cross-Institution Exposure',
            'landing_f1s' => 'Consolidated borrower debt across all reporting institutions.',
            'landing_f2' => 'Consent-Gated Inquiries',
            'landing_f2s' => 'No credit check without recorded borrower consent.',
            'landing_f3' => 'Tamper-Proof Audit',
            'landing_f3s' => 'Hash-chained logs of every search, query and write.',
            'landing_cta' => 'Sign in to the registry',
            // terms
            'terms_title' => 'Terms & Conditions of Access',
            'terms_version' => 'First National Credit Registry Bureau (FNCRB) — Central Credit Registry, Cameroon (CEMAC). Version T&C-2026-09.',
            // dashboard
            'dash_title' => 'Dashboard',
            'dash_institutions' => 'Institutions reporting',
            'dash_borrowers' => 'Registered borrowers',
            'dash_active_loans' => 'Active loans',
            'dash_outstanding' => 'Outstanding portfolio (XAF)',
            'dash_npl' => 'NPL loans (Doubtful/Compromised)',
            'dash_incidents' => 'Open payment incidents (CIP)',
            'dash_regulator_view' => 'Regulator view (COBAC/BEAC): figures cover all reporting institutions.',
            'dash_workflow' => 'Mandatory workflow reminder',
            'dash_workflow_body' => 'Category 1 MFIs must query the registry before approving member credit. Category 2 micro-banks perform real-time API inquiries during underwriting and submit borrower performance and dishonored instruments. Category 3 institutions run comprehensive credit history checks for uncollateralized project finance.',
            // status bar
            'err_otp' => 'Invalid or missing one-time code. Enter the 6-digit code from your authenticator app.',
            'nav_analytics' => 'Analytics',
            'nav_disputes' => 'Disputes',
            'nav_users' => 'Users',
            'nav_account' => 'My Account',
            'status_ready' => 'Ready — FNCRB Central Credit Registry',
            'status_consent' => 'Consent required for all inquiries',
            'status_auth' => 'AUTHENTICATED',
            'status_guest' => 'GUEST',
        ],
        'fr' => [
            'app_name' => 'Premier Bureau National du Registre de Crédit',
            'sign_in' => 'Se connecter',
            'sign_out' => 'Se déconnecter',
            'email' => 'Adresse e-mail',
            'password' => 'Mot de passe',
            'search' => 'Rechercher',
            'submit' => 'Soumettre',
            'close_window' => 'Fermer la fenêtre',
            'back_login' => 'Retour à la connexion',
            'language' => 'Langue',
            'welcome' => 'Bienvenue',
            'nav_dashboard' => 'Tableau de bord',
            'nav_inquiry' => 'Interrogation de crédit',
            'nav_borrowers' => 'Emprunteurs',
            'nav_loans' => 'Portefeuille de prêts',
            'nav_collateral' => 'Sûretés',
            'nav_incidents' => 'Incidents',
            'nav_compliance' => 'Conformité',
            'nav_audit' => 'Piste d’audit',
            'nav_section_registry' => 'Registre',
            'nav_section_regulatory' => 'Réglementaire',
            'login_title' => 'Premier Bureau National du Registre de Crédit',
            'login_sub' => 'Centrale des Risques — Cameroun (CEMAC)',
            'login_terms_label' => 'J’ai lu et j’accepte les',
            'login_terms_link' => 'Conditions générales d’utilisation',
            'login_terms_suffix' => 'du Registre Central de Crédit FNCRB.',
            'login_note' => 'Accès réservé aux participants autorisés du registre. Toutes les tentatives d’accès sont journalisées et auditées.',
            'err_credentials' => 'L’e-mail et le mot de passe sont obligatoires.',
            'err_invalid' => 'Identifiants invalides.',
            'err_throttled' => 'Trop de tentatives échouées. Compte temporairement verrouillé — réessayez plus tard.',
            'err_terms' => 'Vous devez examiner et accepter les Conditions générales avant de vous connecter.',
            'err_account' => 'Le compte est',
            'err_contact_admin' => '. Contactez votre administrateur.',
            'landing_lead' => 'Centrale des Risques du Cameroun (CEMAC) — atténuation du risque de crédit interinstitutionnel pour les institutions de microfinance de Catégories 1, 2 et 3 et les banques commerciales.',
            'landing_reg' => 'Cadre réglementaire :',
            'landing_f1' => 'Exposition interinstitutionnelle',
            'landing_f1s' => 'Consolidation de la dette des emprunteurs auprès de toutes les institutions déclarantes.',
            'landing_f2' => 'Interrogations subordonnées au consentement',
            'landing_f2s' => 'Aucune consultation de crédit sans consentement enregistré de l’emprunteur.',
            'landing_f3' => 'Audit infalsifiable',
            'landing_f3s' => 'Journaux chaînés par hachage de chaque recherche, interrogation et écriture.',
            'landing_cta' => 'Accéder au registre',
            'terms_title' => 'Conditions générales d’accès',
            'terms_version' => 'Premier Bureau National du Registre de Crédit (FNCRB) — Centrale des Risques, Cameroun (CEMAC). Version CGU-2026-09.',
            'dash_title' => 'Tableau de bord',
            'dash_institutions' => 'Institutions déclarantes',
            'dash_borrowers' => 'Emprunteurs enregistrés',
            'dash_active_loans' => 'Prêts actifs',
            'dash_outstanding' => 'Encours du portefeuille (XAF)',
            'dash_npl' => 'Prêts en souffrance (Douteux/Compromis)',
            'dash_incidents' => 'Incidents de paiement ouverts (CIP)',
            'dash_regulator_view' => 'Vue régulateur (COBAC/BEAC) : les chiffres couvrent toutes les institutions déclarantes.',
            'dash_workflow' => 'Rappel du processus obligatoire',
            'dash_workflow_body' => 'Les IMF de Catégorie 1 doivent consulter le registre avant d’approuver un crédit à un membre. Les micro-banques de Catégorie 2 effectuent des interrogations API en temps réel lors de l’octroi et déclarent la performance des emprunteurs et les instruments impayés. Les institutions de Catégorie 3 réalisent des vérifications complètes de l’historique de crédit pour le financement de projets sans garantie.',
            'err_otp' => 'Code à usage unique invalide ou manquant. Saisissez le code à 6 chiffres de votre application d’authentification.',
            'nav_analytics' => 'Analytique',
            'nav_disputes' => 'Litiges',
            'nav_users' => 'Utilisateurs',
            'nav_account' => 'Mon compte',
            'status_ready' => 'Prêt — Centrale des Risques FNCRB',
            'status_consent' => 'Consentement requis pour toute interrogation',
            'status_auth' => 'AUTHENTIFIÉ',
            'status_guest' => 'INVITÉ',
        ],
    ];

    public static function init(): void
    {
        $qs = $_GET['lang'] ?? null;
        if ($qs !== null && in_array($qs, self::ALLOWED, true)) {
            self::$lang = $qs;
            $_SESSION['_lang'] = $qs;
        } elseif (isset($_SESSION['_lang']) && in_array($_SESSION['_lang'], self::ALLOWED, true)) {
            self::$lang = $_SESSION['_lang'];
        }
    }

    public static function lang(): string
    {
        return self::$lang;
    }

    /** Translate a key; falls back to English, then the key itself. */
    public static function t(string $key): string
    {
        return self::STRINGS[self::$lang][$key]
            ?? self::STRINGS[self::DEFAULT][$key]
            ?? $key;
    }
}
