import { useState } from "react";
import { Eye, EyeOff } from "lucide-react";

export default function AuthField({ id, label, type = "text", error, ...inputProps }) {
  const [visible, setVisible] = useState(false);
  const isPassword = type === "password";

  return (
    <div className="auth-field">
      <label htmlFor={id}>{label}</label>
      <div className={`auth-input-wrap${error ? " auth-input-error" : ""}`}>
        <input
          id={id}
          name={id}
          type={isPassword && visible ? "text" : type}
          aria-invalid={Boolean(error)}
          aria-describedby={error ? `${id}-error` : undefined}
          {...inputProps}
        />
        {isPassword && (
          <button
            className="auth-password-toggle"
            type="button"
            onClick={() => setVisible((current) => !current)}
            aria-label={visible ? `Masquer ${label.toLowerCase()}` : `Afficher ${label.toLowerCase()}`}
          >
            {visible ? <EyeOff size={18} aria-hidden="true" /> : <Eye size={18} aria-hidden="true" />}
          </button>
        )}
      </div>
      {error && <p className="auth-field-error" id={`${id}-error`} role="alert">{error}</p>}
    </div>
  );
}
