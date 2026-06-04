"use client";

import { CheckCircle2, Info, TriangleAlert, XCircle } from "lucide-react";
import { Slide, ToastContainer, type IconProps } from "react-toastify";
import "react-toastify/dist/ReactToastify.css";

function ToastIcon({ type }: IconProps) {
  if (type === "success") {
    return <CheckCircle2 className="size-5 text-[var(--cor-first)]" />;
  }

  if (type === "error") {
    return <XCircle className="size-5 text-red-400" />;
  }

  if (type === "warning") {
    return <TriangleAlert className="size-5 text-amber-400" />;
  }

  return <Info className="size-5 text-[var(--cor-fourth)]" />;
}

export function AppToaster() {
  return (
    <ToastContainer
      position="top-right"
      autoClose={2500}
      newestOnTop
      closeOnClick
      pauseOnHover
      draggable={false}
      theme="dark"
      icon={ToastIcon}
      toastClassName="nitro-toast"
      transition={Slide}
    />
  );
}
