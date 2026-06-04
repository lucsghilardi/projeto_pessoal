export const AUTH_COOKIE_NAME = "nitrogym_admin_session";

export const BACKEND_API_URL =
  process.env.INTERNAL_API_URL ??
  process.env.NEXT_PUBLIC_API_URL ??
  "http://localhost:8000/api";

export function getAuthCookieMaxAge(expiresIn?: number) {
  if (typeof expiresIn === "number" && Number.isFinite(expiresIn) && expiresIn > 0) {
    return Math.floor(expiresIn);
  }

  return 60 * 60;
}
