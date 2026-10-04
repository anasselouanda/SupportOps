import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { ArrowRight, CircleAlert, LoaderCircle } from "lucide-react";
import { useAuth } from "../auth/useAuth";
import AuthLayout from "../components/AuthLayout";
import AuthField from "../components/AuthField";
import { getAuthError } from "../components/authErrors";

export default function LoginPage() {
  const navigate = useNavigate();
  const { login, sessionError } = useAuth();
  const [form, setForm] = useState({ email: "", password: "" });
  const [fieldErrors, setFieldErrors] = useState({});
  const [formError, setFormError] = useState(null);
  const [pending, setPending] = useState(false);

  function updateField(event) {
    const { name, value } = event.target;
    setForm((current) => ({ ...current, [name]: value }));
    setFieldErrors((current) => ({ ...current, [name]: undefined }));
    setFormError(null);
  }

  async function handleSubmit(event) {
    event.preventDefault();
    if (pending) return;

    setPending(true);
    setFieldErrors({});
    setFormError(null);
    try {
      await login(form);
      navigate("/dashboard", { replace: true });
    } catch (error) {
      const details = getAuthError(error, { login: true });
      setFieldErrors(details.fieldErrors);
      setFormError(details.formError);
    } finally {
      setPending(false);
    }
  }

  const notice = formError || sessionError;

  return (
    <AuthLayout
      eyebrow="Bienvenue"
      title="Connexion"
      description="Connectez-vous à votre espace SupportOps."
      footer={<>Vous n’avez pas encore de compte ? <Link to="/register">Créer un compte</Link></>}
    >
      {notice && (
        <div className="auth-alert" role="alert">
          <CircleAlert size={19} aria-hidden="true" />
          <span>{notice}</span>
        </div>
      )}
      <form className="auth-form" onSubmit={handleSubmit} noValidate>
        <AuthField
          id="email"
          label="Adresse e-mail"
          type="email"
          autoComplete="email"
          placeholder="nom@entreprise.com"
          value={form.email}
          onChange={updateField}
          error={fieldErrors.email}
          required
        />
        <AuthField
          id="password"
          label="Mot de passe"
          type="password"
          autoComplete="current-password"
          placeholder="Votre mot de passe"
          value={form.password}
          onChange={updateField}
          error={fieldErrors.password}
          required
        />
        <button className="auth-primary-button" type="submit" disabled={pending}>
          {pending ? <LoaderCircle className="auth-spinner" size={19} aria-hidden="true" /> : null}
          <span>{pending ? "Connexion en cours…" : "Se connecter"}</span>
          {!pending && <ArrowRight size={18} aria-hidden="true" />}
        </button>
      </form>
    </AuthLayout>
  );
}
