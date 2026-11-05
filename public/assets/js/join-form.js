// The public joining form (/join/{token}). Not needed for the form to work.
//
// A page restored from the back/forward cache is the old page as it was,
// CSRF token included, while the session that token belonged to may be gone.
// Reloading asks the server for a fresh form, token and timer instead of
// letting the next submit fail. An external file, not an inline script: the
// CSP allows scripts from 'self' only.
window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
        window.location.reload();
    }
});
