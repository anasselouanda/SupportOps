import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { ArrowRight, CircleAlert, LoaderCircle } from "lucide-react";
import { useAuth } from "../auth/useAuth";
import AuthLayout from "../components/AuthLayout";
import AuthField from "../components/AuthField";
import { getAuthError } from "../components/authErrors";

export default function RegisterPage() {
  const navigate = useNavigate();
  const { register, sessionError } = useAuth();
  const [form, setForm] = useState({
    name: "",
    email: "",
    password: "",
    password_confirmation: "",
  });
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
      await register(form);
      navigate("/dashboard", { replace: true });
    } catch (error) {
      const details = getAuthError(error);
      setFieldErrors(details.fieldErrors);
      setFormError(details.formError);
    } finally {
      setPending(false);
    }
  }

  const notice = formError || sessionError;

  return (
    <AuthLayout
      eyebrow="Commencer"
      title="Créer un compte"
      description="Quelques informations suffisent pour accéder à votre espace."
      footer={<>Vous avez déjà un compte ? <Link to="/login">Se connecter</Link></>}
    >
      {notice && (
        <div className="auth-alert" role="alert">
          <CircleAlert size={19} aria-hidden="true" />
          <span>{notice}</span>
        </div>
      )}
      <form className="auth-form" onSubmit={handleSubmit} noValidate>
        <AuthField
          id="name"
          label="Nom complet"
          autoComplete="name"
          placeholder="Votre nom"
          value={form.name}
          onChange={updateField}
          error={fieldErrors.name}
          required
        />
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
          autoComplete="new-password"
          placeholder="Au moins 8 caractères"
          value={form.password}
          onChange={updateField}
          error={fieldErrors.password}
          required
        />
        <AuthField
          id="password_confirmation"
          label="Confirmer le mot de passe"
          type="password"
          autoComplete="new-password"
          placeholder="Répétez votre mot de passe"
          value={form.password_confirmation}
          onChange={updateField}
          error={fieldErrors.password_confirmation}
          required
        />
        <button className="auth-primary-button" type="submit" disabled={pending}>
          {pending ? <LoaderCircle className="auth-spinner" size={19} aria-hidden="true" /> : null}
          <span>{pending ? "Création en cours…" : "Créer mon compte"}</span>
          {!pending && <ArrowRight size={18} aria-hidden="true" />}
        </button>
      </form>
    </AuthLayout>
  );
}
