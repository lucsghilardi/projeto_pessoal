import { ApiError, UnauthorizedError } from './apiError';
import { CreateUserPayload, UpdateUserPayload, User } from '@/types/User';

const API_URL = '/api/proxy';

async function parseApiResponse<T>(res: Response): Promise<T> {
    if (!res.ok) {
        if (res.status === 401) {
            throw new UnauthorizedError();
        }

        const responseText = await res.text();
        let parsedBody: {
            message?: string;
            errors?: Record<string, string[]>;
        } | null = null;

        if (responseText) {
            try {
                parsedBody = JSON.parse(responseText) as {
                    message?: string;
                    errors?: Record<string, string[]>;
                };
            } catch {
                parsedBody = null;
            }
        }

        if (parsedBody) {
            const firstValidationError = parsedBody.errors
                ? Object.values(parsedBody.errors).flat()[0]
                : null;

            throw new ApiError(
                res.status,
                firstValidationError || parsedBody.message || 'Erro na API'
            );
        }

        throw new ApiError(res.status, responseText || 'Erro na API');
    }

    if (res.status === 204) {
        return null as T;
    }

    const text = await res.text();
    return text ? (JSON.parse(text) as T) : (null as T);
}

export async function apiFetch<T>(
    path: string,
    options: RequestInit = {}
): Promise<T> {
    const headers = new Headers(options.headers ?? {});
    const isFormData = typeof FormData !== 'undefined' && options.body instanceof FormData;

    if (!isFormData && !headers.has('Content-Type')) {
        headers.set('Content-Type', 'application/json');
    }

    const res = await fetch(`${API_URL}${path}`, {
        ...options,
        credentials: 'same-origin',
        headers,
    });

    return parseApiResponse<T>(res);
}

export async function login(
    email: string,
    password: string,
): Promise<void> {
    const res = await fetch('/api/auth/login', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ email, password }),
    });

    await parseApiResponse<{ authenticated: boolean }>(res);
}

export async function logout(): Promise<void> {
    const res = await fetch('/api/auth/logout', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
        },
    });

    await parseApiResponse<{ authenticated: boolean }>(res);
}

export function getUsers() {
    return apiFetch<User[]>('/users');
}

export function createUser(data: CreateUserPayload) {
    return apiFetch<User>('/users', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function updateUser(id: number, data: UpdateUserPayload) {
    return apiFetch<User>(`/users/${id}`, {
        method: 'PUT',
        body: JSON.stringify(data),
    });
}
