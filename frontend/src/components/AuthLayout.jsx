import { ArrowUpRight, LifeBuoy, ShieldCheck } from "lucide-react";

export default function AuthLayout({ eyebrow, title, description, children, footer }) {
  return (
    <div className="auth-page">
      <aside className="auth-story" aria-label="Présentation de SupportOps">
        <div className="auth-brand">
          <span className="auth-brand-mark" aria-hidden="true">
            <LifeBuoy size={22} strokeWidth={2.2} />
          </span>
          <span>SupportOps</span>
        </div>

        <div className="auth-story-content">
          <span className="auth-story-label">Votre espace support</span>
          <h2>Un point d’entrée simple pour avancer sereinement.</h2>
          <p>
            Retrouvez votre espace de support informatique dans une interface
            claire, pensée pour rester concentré sur l’essentiel.
          </p>
          <div className="auth-story-note">
            <ShieldCheck size={19} aria-hidden="true" />
            <span>Accès protégé par votre session</span>
            <ArrowUpRight size={16} aria-hidden="true" />
          </div>
        </div>

        <p className="auth-story-bottom">SupportOps · Espace personnel</p>
      </aside>

      <main className="auth-main">
        <div className="auth-mobile-brand" aria-hidden="true">
          <span className="auth-brand-mark"><LifeBuoy size={20} strokeWidth={2.2} /></span>
          <span>SupportOps</span>
        </div>
        <div className="auth-content">
          <div className="auth-heading">
            <span className="auth-eyebrow">{eyebrow}</span>
            <h1>{title}</h1>
            <p>{description}</p>
          </div>
          {children}
          {footer && <div className="auth-footer">{footer}</div>}
        </div>
        <p className="auth-main-bottom">© {new Date().getFullYear()} SupportOps</p>
      </main>
    </div>
  );
}
