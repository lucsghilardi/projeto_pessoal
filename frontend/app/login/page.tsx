import { LoginForm } from "@/components/login-form"

export default function LoginPage() {
  return (
    <div className="min-h-svh bg-[linear-gradient(180deg,_#ffffff_0%,_#f5f5f4_100%)] px-6 py-10 md:px-10 md:py-12 flex items-center">
      <div className="mx-auto flex w-full max-w-5xl items-center">
        <LoginForm className="w-full" />
      </div>
    </div>
  )
}
