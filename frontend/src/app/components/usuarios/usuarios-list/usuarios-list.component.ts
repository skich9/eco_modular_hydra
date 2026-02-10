import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule, FormBuilder, FormGroup, Validators, ValidationErrors } from '@angular/forms';
import { RouterModule } from '@angular/router';
import { UsuarioFormComponent } from '../usuario-form/usuario-form.component';
import { Usuario, Rol } from '../../../models/usuario.model';
import { UsuarioService } from '../../../services/usuario.service';
import { RolService } from '../../../services/rol.service';

@Component({
    selector: 'app-usuarios-list',
    standalone: true,
    imports: [CommonModule, FormsModule, ReactiveFormsModule, RouterModule, UsuarioFormComponent],
    templateUrl: './usuarios-list.component.html',
    styleUrls: ['./usuarios-list.component.scss']
})
export class UsuariosListComponent implements OnInit {
    allUsuarios: Usuario[] = [];
    filteredUsuarios: Usuario[] = [];
    paginatedUsuarios: Usuario[] = [];
    searchTerm = '';
    isLoading = true;
    showDeleteModal = false;
    usuarioToDelete: Usuario | null = null;
    // Modal de creación
    showCreateModal = false;
    createForm!: FormGroup;
    createError = '';
    isCreating = false;
    createSubmitted = false;
    roles: Rol[] = [];
    showPassword = false;
    showConfirmPassword = false;

    // Modal de edición
    showEditModal = false;
    editUsuarioId: number | null = null;

    // Paginación
    currentPage = 1;
    pageSize = 10;
    totalPages = 1;

    constructor(
        private usuarioService: UsuarioService,
        private rolService: RolService,
        private fb: FormBuilder
    ) {}

    ngOnInit(): void {
        this.loadUsuarios();
        this.loadRoles();
    }

    loadUsuarios(): void {
        this.isLoading = true;
        this.usuarioService.getAll().subscribe({
            next: (response: { success: boolean; data: Usuario[] }) => {
                if (response.success && response.data) {
                    this.allUsuarios = response.data;
                    this.filteredUsuarios = [...this.allUsuarios];
                    this.applyPagination();
                }
                this.isLoading = false;
            },
            error: (error: any) => {
                console.error('Error al cargar usuarios:', error);
                this.isLoading = false;
            }
        });
    }

    loadRoles(): void {
        this.rolService.getActiveRoles().subscribe({
            next: (res: { success: boolean; data: Rol[] }) => {
                if (res.success && res.data) {
                    this.roles = res.data;
                }
            },
            error: (err: any) => {
                console.error('Error al cargar roles activos:', err);
            }
        });
    }

    onSearch(): void {
        if (this.searchTerm.trim() === '') {
            this.filteredUsuarios = [...this.allUsuarios];
        } else {
            const term = this.searchTerm.toLowerCase().trim();
            this.filteredUsuarios = this.allUsuarios.filter(usuario =>
                usuario.nickname.toLowerCase().includes(term) ||
                usuario.nombre.toLowerCase().includes(term) ||
                usuario.ap_paterno.toLowerCase().includes(term) ||
                usuario.ci.toLowerCase().includes(term) ||
                (usuario.rol?.nombre && usuario.rol.nombre.toLowerCase().includes(term))
            );
        }
        this.currentPage = 1;
        this.applyPagination();
    }

    toggleUsuarioStatus(usuario: Usuario): void {
        this.usuarioService.toggleStatus(usuario.id_usuario).subscribe({
            next: (response: { success: boolean; data: Usuario; message: string }) => {
                if (response.success && response.data) {
                    // Actualizar el usuario en la lista local
                    const index = this.allUsuarios.findIndex(u => u.id_usuario === usuario.id_usuario);
                    if (index !== -1) {
                        this.allUsuarios[index].estado = !this.allUsuarios[index].estado;
                        this.onSearch(); // Reaplica filtros y paginación
                    }
                }
            },
            error: (error: any) => {
                console.error('Error al cambiar estado del usuario:', error);
            }
        });
    }

    confirmDelete(usuario: Usuario): void {
        this.usuarioToDelete = usuario;
        this.showDeleteModal = true;
    }

    cancelDelete(): void {
        this.usuarioToDelete = null;
        this.showDeleteModal = false;
    }

    deleteUsuario(): void {
        if (!this.usuarioToDelete) return;

        this.usuarioService.delete(this.usuarioToDelete.id_usuario).subscribe({
            next: (response: { success: boolean; message: string }) => {
                if (response.success) {
                    // Eliminar el usuario de la lista local
                    this.allUsuarios = this.allUsuarios.filter(u => u.id_usuario !== this.usuarioToDelete?.id_usuario);
                    this.onSearch(); // Reaplica filtros y paginación
                }
                this.showDeleteModal = false;
                this.usuarioToDelete = null;
            },
            error: (error: any) => {
                console.error('Error al eliminar usuario:', error);
                this.showDeleteModal = false;
                this.usuarioToDelete = null;
            }
        });
    }

    applyPagination(): void {
        this.totalPages = Math.ceil(this.filteredUsuarios.length / this.pageSize) || 1;

        // Asegurarse de que la página actual es válida
        if (this.currentPage < 1) this.currentPage = 1;
        if (this.currentPage > this.totalPages) this.currentPage = this.totalPages;

        const startIndex = (this.currentPage - 1) * this.pageSize;
        const endIndex = startIndex + this.pageSize;
        this.paginatedUsuarios = this.filteredUsuarios.slice(startIndex, endIndex);
    }

    goToPage(page: number): void {
        if (page >= 1 && page <= this.totalPages && page !== this.currentPage) {
            this.currentPage = page;
            this.applyPagination();
        }
    }

    // ====== Modal de creación ======
    openCreateModal(): void {
        this.createError = '';
        this.showCreateModal = true;
    }

    closeCreateModal(): void {
        this.showCreateModal = false;
    }

    initCreateForm(): void {
        this.createSubmitted = false;
        this.createForm = this.fb.group({
            nickname: ['', Validators.required],
            nombre: ['', Validators.required],
            ap_paterno: ['', Validators.required],
            ap_materno: [''],
            ci: ['', Validators.required],
            id_rol: [null, Validators.required],
            estado: [true],
            contrasenia: ['', [Validators.required, Validators.minLength(6)]],
            contraseniaConfirm: ['', [Validators.required]]
        }, { validators: this.passwordsMatchValidator });
    }

    passwordsMatchValidator = (group: FormGroup): ValidationErrors | null => {
        const p = group.get('contrasenia')?.value;
        const pc = group.get('contraseniaConfirm')?.value;
        return p === pc ? null : { matching: true };
    };

    onCreateClosed(saved: boolean): void {
        this.showCreateModal = false;
        if (saved) {
            this.loadUsuarios();
        }
    }

    togglePasswordVisibility(): void {
        this.showPassword = !this.showPassword;
    }

    toggleConfirmPasswordVisibility(): void {
        this.showConfirmPassword = !this.showConfirmPassword;
    }

    submitCreateForm(): void {
        this.createSubmitted = true;
        if (this.createForm.invalid) return;

        this.isCreating = true;
        const { contraseniaConfirm, ...payload } = this.createForm.value;
        this.usuarioService.create(payload).subscribe({
            next: (res: { success: boolean; data: Usuario; message: string }) => {
                if (res.success) {
                    this.closeCreateModal();
                    this.loadUsuarios();
                } else {
                    this.createError = res.message || 'Error al crear usuario';
                }
                this.isCreating = false;
            },
            error: (err: any) => {
                console.error('Error al crear usuario:', err);
                this.createError = err.error?.message || 'Error al crear usuario. Intente nuevamente más tarde.';
                this.isCreating = false;
            }
        });
    }

    // ====== Modal de edición ======
    openEditModal(usuario: Usuario): void {
        this.editUsuarioId = usuario.id_usuario;
        this.showEditModal = true;
    }

    closeEditModal(): void {
        this.showEditModal = false;
        this.editUsuarioId = null;
    }

    onEditClosed(saved: boolean): void {
        this.showEditModal = false;
        this.editUsuarioId = null;
        if (saved) {
            this.loadUsuarios();
        }
    }
}
