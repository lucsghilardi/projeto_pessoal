"use client";

import { createContext, useContext, useEffect, useState } from "react";
import { User } from "@/types/User";
import { apiFetch, logout as logoutRequest } from "@/services/api";
import { UnauthorizedError } from "@/services/apiError";

interface AuthContextType {
  user: User | null;
  loading: boolean;
  logout: () => void;
}

const AuthContext = createContext<AuthContextType | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let isActive = true;

    apiFetch<User>("/me")
      .then((authenticatedUser) => {
        if (isActive) {
          setUser(authenticatedUser);
        }
      })
      .catch((error) => {
        if (error instanceof UnauthorizedError) {
          void logoutRequest()
            .catch(() => null)
            .finally(() => {
              if (isActive) {
                setUser(null);
                setLoading(false);
              }

              window.location.replace("/login");
            });

          return;
        }

        if (isActive) {
          setUser(null);
        }
      })
      .finally(() => {
        if (isActive) {
          setLoading(false);
        }
      });

    return () => {
      isActive = false;
    };
  }, []);

  function logout() {
    void logoutRequest()
      .catch(() => null)
      .finally(() => {
        setUser(null);
        window.location.replace("/login");
      });
  }

  return (
    <AuthContext.Provider value={{ user, loading, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error("useAuth must be used inside AuthProvider");
  }
  return ctx;
}
